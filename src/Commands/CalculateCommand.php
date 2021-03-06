<?php

namespace Lavoaster\AWSClientPricing\Commands;

use Lavoaster\AWSClientPricing\AWS\Calculator;
use Lavoaster\AWSClientPricing\AWS\Factories\PriceFactory;
use Lavoaster\AWSClientPricing\Definition\AllOnDemand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

class CalculateCommand extends BaseCommand
{
    public function configure()
    {
        $this->setName('calculate');
    }

    /**
     * @param PriceFactory $priceFactory
     * @throws \Exception
     */
    public function handle(PriceFactory $priceFactory)
    {
        $this->output->title('Calculator');

        $definitions = AllOnDemand::getAll();

        $this->output->section('Loading Data into Memory');

        $services = [];

        foreach ($definitions as $key => $definition) {
            foreach ($definition['items'] as $service => $resources) {
                $services[] = $service;
            }
        }

        $services = array_unique($services);

        foreach ($services as $service) {
            $this->output->write(' Loading ' . $service . '...');

            $priceFactory->loadOffering($service);

            $this->output->writeln('Done');
        }

        $this->output->comment('Memory: ' . round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB');

        $this->output->section('Calculation');

        $calculator = new Calculator($priceFactory);

        $this->output->note([
            'Calculation Methodology:',
            'Hourly - Raw price',
            'Daily - Hourly * 24',
            'Monthly - Hourly * 730',
            'Yearly - (Hourly * 24 * 365) + One-Time 1yr',
            'Year One - Yearly + One-Time 3yr',
            'Year Three - (Yearly * 3) + One-Time 3yr',
        ]);
        $this->output->note('One Time fees are not spread out into hourly, daily, or monthly costs');

        foreach ($definitions as $key => $definition) {
            $this->output->section($key);

            $this->calculateDefinition($calculator, $definition);
        }
    }

    /**
     * @param $calculator
     * @param $definition
     * @throws \Exception
     */
    private function calculateDefinition(Calculator $calculator, $definition)
    {
        $calculatedDefinition = $calculator->calculate($definition);

        $support = [
            'Support' => [
                'pricing' => $this->calculateSupport($calculatedDefinition['pricing']),
            ],
        ];

        $tableData = array_map(
            [$this, 'formatServices'],
            $calculatedDefinition['items'],
            array_keys($calculatedDefinition['items'])
        );

        $tableData[] = new TableSeparator();
        $tableData[] = $this->formatPricing('Support', $support['Support']['pricing']);
        $tableData[] = new TableSeparator();
        $tableData[] = $this->formatPricing('Total', Calculator::calculateTotal(array_merge($calculatedDefinition['items'], $support)));

        $headers = [
            'Service',
            'One-Time 1yr',
            'One-Time 3yr',
            'Hourly',
            'Daily',
            'Monthly',
            'Yearly',
            'Year One',
            'Year Three',
        ];

        $table = new Table($this->output);

        $table->setHeaders($headers);
        $table->setRows($tableData);

        $table->render();
    }

    private function calculateSupport(array $pricing): array
    {
        $monthly = $this->calculateSupportCost($pricing['monthly']);
        $oneTime1yr = $this->calculateSupportCost($pricing['oneTime1yr'] ?? '0.00');
        $oneTime3yr = $this->calculateSupportCost($pricing['oneTime3yr'] ?? '0.00');

        $pricing['oneTime1yr'] = $oneTime1yr;
        $pricing['oneTime3yr'] = $oneTime3yr;
        $pricing['monthly'] = $monthly;

        $pricing['hourly'] = bcdiv($monthly, 730);
        $pricing['daily'] = bcmul($pricing['hourly'], 24);
        $pricing['yearly'] = bcadd(bcmul($monthly, 12), $oneTime1yr);
        $pricing['yearOne'] = bcadd($pricing['yearly'], $oneTime3yr);
        $pricing['yearThree'] = bcadd(bcmul($pricing['yearly'], 3), $oneTime3yr);

        return $pricing;
    }

    private function calculateSupportCost(string $total): string
    {
        // Note because I'll forget this otherwise:
        //  If support is enabled at a later date, all one-time fees are prorated into the costs.

        $runningValue = $total;
        $supportCost = '0.00';

        $thresholds = [
            '250000' => '0.03',
            '80000' => '0.05',
            '10000' => '0.07',
            '0' => '0.10',
        ];

        foreach ($thresholds as $threshold => $percentage) {
            if ($threshold > $runningValue) {
                continue;
            }

            $valueToCalculateAgainst = bcsub($runningValue, $threshold);
            $costAtThreshold = bcmul($valueToCalculateAgainst, $percentage);
            $supportCost = bcadd($supportCost, $costAtThreshold);
            $runningValue = bcsub($runningValue, $valueToCalculateAgainst);
        }

        return $supportCost;
    }

    private function formatPricing(string $name, array $pricing, $formatNumber = true): array
    {
        $moneyValues = [
            $pricing['oneTime1yr'] ?? '0.00',
            $pricing['oneTime3yr'] ?? '0.00',
            $pricing['hourly'] ?? '0.00',
            $pricing['daily'] ?? '0.00',
            $pricing['monthly'] ?? '0.00',
            $pricing['yearly'] ?? '0.00',
            $pricing['yearOne'] ?? '0.00',
            $pricing['yearThree'] ?? '0.00',
        ];

        if ($formatNumber) {
            $moneyValues = array_map(function ($value) {
                return number_format($value, 2);
            }, $moneyValues);
        }

        return array_merge([
            $name,
        ], $moneyValues);
    }

    private function formatServices(array $service, $key): array
    {
        return $this->formatPricing($key, $service['pricing']);
    }
}
