<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Goals\RecordBuilders;

use Piwik\ArchiveProcessor\Record;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataArray;
use Piwik\DataTable;
use Piwik\Metrics;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Goals\API;
use Piwik\Plugins\Goals\Archiver;
use Piwik\Tracker\GoalManager;

class GeneralGoalsRecords extends Base
{
    const VISITS_UNTIL_RECORD_NAME = 'visits_until_conv';
    const DAYS_UNTIL_CONV_RECORD_NAME = 'days_until_conv';
    const VISITS_COUNT_FIELD = 'visitor_count_visits';
    const LOG_CONVERSION_TABLE = 'log_conversion';
    const SECONDS_SINCE_FIRST_VISIT_FIELD = 'visitor_seconds_since_first';

    /**
     * This array stores the ranges to use when displaying the 'visits to conversion' report
     */
    public static $visitCountRanges = array(
        array(1, 1),
        array(2, 2),
        array(3, 3),
        array(4, 4),
        array(5, 5),
        array(6, 6),
        array(7, 7),
        array(8, 8),
        array(9, 14),
        array(15, 25),
        array(26, 50),
        array(51, 100),
        array(100)
    );

    /**
     * This array stores the ranges to use when displaying the 'days to conversion' report
     */
    public static $daysToConvRanges = array(
        array(0, 0),
        array(1, 1),
        array(2, 2),
        array(3, 3),
        array(4, 4),
        array(5, 5),
        array(6, 6),
        array(7, 7),
        array(8, 14),
        array(15, 30),
        array(31, 60),
        array(61, 120),
        array(121, 364),
        array(364)
    );

    protected function aggregate()
    {
        $prefixes = array(
            self::VISITS_UNTIL_RECORD_NAME    => 'vcv',
            self::DAYS_UNTIL_CONV_RECORD_NAME => 'vdsf',
        );

        $totalConversions = 0;
        $totalRevenue = 0;

        $goals = new DataArray();

        $visitsToConversions = [];
        $daysToConversions = [];

        $siteHasEcommerceOrGoals = $this->hasAnyGoalOrEcommerce($this->getSiteId());

        // Special handling for sites that contain subordinated sites, like in roll up reporting.
        // A roll up site, might not have ecommerce enabled or any configured goals,
        // but if a subordinated site has, we calculate the overview conversion metrics nevertheless
        if ($siteHasEcommerceOrGoals === false) {
            $idSitesToArchive = $this->archiveProcessor->getParams()->getIdSites();

            foreach ($idSitesToArchive as $idSite) {
                if ($this->hasAnyGoalOrEcommerce($idSite)) {
                    $siteHasEcommerceOrGoals = true;
                    break;
                }
            }
        }

        $logAggregator = $this->archiveProcessor->getLogAggregator();

        // try to query goal data only, if goals or ecommerce is actually used
        // otherwise we simply insert empty records
        if ($siteHasEcommerceOrGoals) {
            $selects = [];
            $selects = array_merge($selects, LogAggregator::getSelectsFromRangedColumn(
                self::VISITS_COUNT_FIELD, self::$visitCountRanges, self::LOG_CONVERSION_TABLE, $prefixes[self::VISITS_UNTIL_RECORD_NAME]
            ));
            $selects = array_merge($selects, LogAggregator::getSelectsFromRangedColumn(
                'FLOOR(log_conversion.' . self::SECONDS_SINCE_FIRST_VISIT_FIELD . ' / 86400)', self::$daysToConvRanges, self::LOG_CONVERSION_TABLE, $prefixes[self::DAYS_UNTIL_CONV_RECORD_NAME]
            ));

            $query = $logAggregator->queryConversionsByDimension([], false, $selects);
            if ($query === false) {
                return [];
            }

            $conversionMetrics = $logAggregator->getConversionsMetricFields();
            while ($row = $query->fetch()) {
                $idGoal = $row['idgoal'];
                unset($row['idgoal']);
                unset($row['label']);

                $values = [];
                foreach ($conversionMetrics as $field => $statement) {
                    $values[$field] = $row[$field];
                }
                $goals->sumMetrics($idGoal, $values);

                if (empty($visitsToConversions[$idGoal])) {
                    $visitsToConversions[$idGoal] = new DataTable();
                }
                $array = LogAggregator::makeArrayOneColumn($row, Metrics::INDEX_NB_CONVERSIONS, $prefixes[self::VISITS_UNTIL_RECORD_NAME]);
                $visitsToConversions[$idGoal]->addDataTable(DataTable::makeFromIndexedArray($array));

                if (empty($daysToConversions[$idGoal])) {
                    $daysToConversions[$idGoal] = new DataTable();
                }
                $array = LogAggregator::makeArrayOneColumn($row, Metrics::INDEX_NB_CONVERSIONS, $prefixes[self::DAYS_UNTIL_CONV_RECORD_NAME]);
                $daysToConversions[$idGoal]->addDataTable(DataTable::makeFromIndexedArray($array));

                // We don't want to sum Abandoned cart metrics in the overall revenue/conversions/converted visits
                // since it is a "negative conversion"
                if ($idGoal != GoalManager::IDGOAL_CART) {
                    $totalConversions += $row[Metrics::INDEX_GOAL_NB_CONVERSIONS];
                    $totalRevenue     += $row[Metrics::INDEX_GOAL_REVENUE];
                }
            }
        }

        // Stats by goal, for all visitors
        $numericRecords = $this->getConversionsNumericMetrics($goals);

        $nbConvertedVisits = $this->archiveProcessor->getNumberOfVisitsConverted();

        $result = array_merge([
            // Stats for all goals
            Archiver::getRecordName('nb_conversions')      => $totalConversions,
            Archiver::getRecordName('nb_visits_converted') => $nbConvertedVisits,
            Archiver::getRecordName('revenue')             => $totalRevenue,
        ], $numericRecords);

        // TODO: Remove use of DataArray everywhere, just directly build DataTables (less memory use overall)

        foreach ($visitsToConversions as $idGoal => $table) {
            $recordName = Archiver::getRecordName(self::VISITS_UNTIL_RECORD_NAME, $idGoal);
            $result[$recordName] = $table;
        }
        $result[Archiver::getRecordName(self::VISITS_UNTIL_RECORD_NAME)] = $this->getOverviewFromGoalTables($visitsToConversions);

        foreach ($daysToConversions as $idGoal => $table) {
            $recordName = Archiver::getRecordName(self::DAYS_UNTIL_CONV_RECORD_NAME, $idGoal);
            $result[$recordName] = $table;
        }
        $result[Archiver::getRecordName(self::DAYS_UNTIL_CONV_RECORD_NAME)] = $this->getOverviewFromGoalTables($daysToConversions);

        return $result;
    }

    protected function getOverviewFromGoalTables($tableByGoal)
    {
        $overview = new DataTable();
        foreach ($tableByGoal as $idGoal => $table) {
            if ($this->isStandardGoal($idGoal)) {
                $overview->addDataTable($table);
            }
        }
        return $overview;
    }

    protected function isStandardGoal($idGoal)
    {
        return !in_array($idGoal, $this->getEcommerceIdGoals());
    }

    public function getRecordMetadata()
    {
        $records = [
            Record::make(Record::TYPE_BLOB, Archiver::getRecordName(self::VISITS_UNTIL_RECORD_NAME)),
            Record::make(Record::TYPE_BLOB, Archiver::getRecordName(self::DAYS_UNTIL_CONV_RECORD_NAME)),

            Record::make(Record::TYPE_NUMERIC, Archiver::getRecordName('nb_conversions')),
            Record::make(Record::TYPE_NUMERIC, Archiver::getRecordName('nb_visits_converted')),
            Record::make(Record::TYPE_NUMERIC, Archiver::getRecordName('revenue')),
        ];

        $goals = API::getInstance()->getGoals($this->getSiteId());
        $goals = array_keys($goals);

        if (Manager::getInstance()->isPluginActivated('Ecommerce')) {
            $goals = array_merge($goals, $this->getEcommerceIdGoals());
        }

        foreach ($goals as $idGoal) {
            foreach (Metrics::$mappingFromIdToNameGoal as $metricName) {
                $records[] = Record::make(Record::TYPE_NUMERIC, Archiver::getRecordName($metricName, $idGoal));
            }

            $records[] = Record::make(Record::TYPE_BLOB, Archiver::getRecordName(self::VISITS_UNTIL_RECORD_NAME, $idGoal));
            $records[] = Record::make(Record::TYPE_BLOB, Archiver::getRecordName(self::DAYS_UNTIL_CONV_RECORD_NAME, $idGoal));
        }

        return $records;
    }

    private function hasAnyGoalOrEcommerce($idSite) // TODO: this & other methods like this should probably be in a base class
    {
        return $this->usesEcommerce($idSite) || !empty(GoalManager::getGoalIds($idSite));
    }

    protected function getConversionsNumericMetrics(DataArray $goals)
    {
        $numericRecords = array();
        $goals = $goals->getDataArray();
        foreach ($goals as $idGoal => $array) {
            foreach ($array as $metricId => $value) {
                $metricName = Metrics::$mappingFromIdToNameGoal[$metricId];
                $recordName = Archiver::getRecordName($metricName, $idGoal);
                $numericRecords[$recordName] = $value;
            }
        }
        return $numericRecords;
    }
}