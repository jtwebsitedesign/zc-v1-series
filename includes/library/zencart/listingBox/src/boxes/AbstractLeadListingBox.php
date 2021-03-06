<?php
/**
 * @copyright Copyright 2003-2015 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: currencies.php 15880 2010-04-11 16:24:30Z wilt $
 */
namespace ZenCart\ListingBox\boxes;
/**
 * Class AbstractListingBox
 * @package ZenCart\ListingBox\Box
 */
abstract class AbstractLeadListingBox extends AbstractListingBox
{
    /**
     * @param \ZenCart\Request\Request $request
     */
    public function __construct(\ZenCart\Request\Request $request)
    {
        parent::__construct($request);
        $this->setDefaults();
    }

    /**
     * @param $queryBuilder
     * @param $db
     * @param $derivedItemsManager
     * @param null $paginator
     * @return array
     * @throws \Exception
     */
    public function buildResults($queryBuilder, $db, $derivedItemsManager, $paginator = null)
    {
        $this->tplVars['filter'] = $this->doFilters($db);
        $queryBuilder->processQuery($this->listingQuery);
        $query = $queryBuilder->getQuery();
        $this->dbConn = $query['dbConn'] = $db;
        $resultItems = $this->processPaginatorResults($paginator, $query, $db);
        $resultItems = $this->transformPaginationItems($resultItems);
        $finalItems = $this->processDerivedItems($resultItems, $derivedItemsManager);
        $formatter = $this->doFormatter($finalItems, $db);
        $this->tplVars['formatter'] = $formatter->getTplVars();
        $this->tplVars['formattedItems'] = $formatter->getFormattedResults();
        $this->doMultiFormSubmit($finalItems);
        $this->normalizeTplVars($paginator);
        return $finalItems;
    }
    /**
     * @param $listBoxContents
     */
    public function doMultiFormSubmit($listBoxContents)
    {
    }

    /**
     * @param $items
     * @return array
     */
    public function transformPaginationItems($items)
    {
        if (count($items) == 0) return array();
        $rows = array();
        foreach ($items as $item) {
            $row = array();
            $row = $this->processItemCallables($row, $item);
            $row ['rowActions'] = array();
            if ($this->leadDefinition ['allowEdit']) {
                $row ['rowActions'] ['edit'] = array(
                    'link' => zen_href_link($this->request->readGet('cmd'), zen_get_all_get_params(array(
                            'action'
                        )) . 'action=edit&' . $this->listingQuery ['mainTable']['fkeyFieldLeft'] . '=' . $item [$this->listingQuery ['mainTable']['fkeyFieldLeft']]),
                    'linkText' => TEXT_LEAD_EDIT,
                    'linkParameters' => ''
                );
            }
            if ($this->leadDefinition ['allowDelete']) {
                $row ['rowActions'] ['delete'] = array(
                    'link' => '#',
                    'linkText' => TEXT_LEAD_DELETE,
                    'linkParameters' => 'class="rowDelete" data-item="' . $item [$this->listingQuery ['mainTable']['fkeyFieldLeft']] . '"'
                );
            }
            if (isset($this->leadDefinition ['extraRowActions'])) {
                $row = $this->processItemExtraRowActions($row, $item);
            }
            $rows [] = $row;
        }
        return $rows;
    }

    /**
     * @param $row
     * @param $item
     * @return mixed
     */
    protected function processItemExtraRowActions($row, $item)
    {
        foreach ($this->leadDefinition ['extraRowActions'] as $extraRowActions) {
            $row ['rowActions'] [$extraRowActions ['key']] = array(
                'link' => $this->buildExtraActionLink($extraRowActions ['link'], $item),
                'linkText' => $extraRowActions ['linkText'],
                'linkParameters' => isset($extraRowActions ['linkParameters']) ? $extraRowActions ['linkParameters'] : ''
            );
        }
        return $row;
    }

    /**
     * @param $parameters
     * @param $item
     * @return string|void
     */
    public function buildExtraActionLink($parameters, $item)
    {
        $actions = '';
        if (!isset($parameters ['params'])) {
            return zen_href_link($parameters ['cmd']);
        }
        foreach ($parameters ['params'] as $param) {
            if ($param['type'] == 'item') {
                $actions .= $param ['name'] . '=' . $item [$param ['value']] . '&';
            }
            if ($param['type'] == 'text') {
                $actions .= $param ['name'] . '=' . $param ['value'] . '&';
            }
        }
        $result = zen_href_link($parameters ['cmd'], $actions);
        return $result;
    }

    /**
     * @param $row
     * @param $item
     * @return mixed
     */
    protected function processItemCallables($row, $item)
    {
        $fkeyFieldLeft = $this->listingQuery ['mainTable']['fkeyFieldLeft'];
        foreach ($this->leadDefinition ['fields'] as $field => $options) {
            $row [$field] = $item [$field];
            if (isset($options ['fieldFormatter'] ['callable']) && $this->validateCallableContext($options ['fieldFormatter'])) {
                $entry = $this->manageCustomRowItem($field, $item, $fkeyFieldLeft, $options ['fieldFormatter'] ['callable']);
                $row [$field] = $entry;
            }
        }
        return $row;
    }

    protected function validateCallableContext($fieldFormatter)
    {
        if (isset($fieldFormatter['context']) && in_array($this->leadDefinition ['action'], $fieldFormatter['context'])) {
            return true;
        }
        if (!isset($fieldFormatter['context']) && $this->leadDefinition ['action'] == 'list') {
            return true;
        }
    }

    /**
     *
     * @param unknown $key
     * @param unknown $item
     * @param unknown $pkey
     * @param unknown $method
     * @return mixed
     */
    public function manageCustomRowItem($key, $item, $pkey, $method)
    {
        if (is_object($method) and $method instanceof \Closure) {
            $itemValue = call_user_func($method, $item, $key, $pkey);

            return $itemValue;
        }
        if (strpos($method, '::') !== false) {
            $itemValue = call_user_func($method, $item, $key, $pkey);

            return $itemValue;
        }
        if (is_object($this->service) && method_exists($this->service, $method)) {
            $itemValue = call_user_func(array($this->service, $method), $item, $key, $pkey);

            return $itemValue;
        }
        $itemValue = call_user_func(array($this, $method), $item, $key, $pkey);
        return $itemValue;
    }

    /**
     *
     */
    public function setDefaults()
    {
        $this->listingQuery['languageKeyField'] = isset($this->listingQuery['languageKeyField']) ? $this->listingQuery['languageKeyField'] : 'languages_id';
        if (!isset($this->outputLayout['formatter'])) {
            $this->outputLayout['formatter'] = array('class'=> 'AdminLead');
        }
    }

    /**
     * @param $leadDefinition
     */
    public function setLeadDefinition($leadDefinition)
    {
        $this->leadDefinition = $leadDefinition;
    }

    /**
     *
     * @param unknown $item
     * @param unknown $key
     * @param unknown $pkey
     * @return string
     */
    public function statusIconUpdater($item, $key, $pkey)
    {
        $newVal = $item[$key] ^= 1;
        $icon = 'icon_red_on.gif';
        if (!$newVal) $icon = 'icon_green_on.gif';
        return '<a class="ajaxDataUpdater" data-action="updateField" data-pkey="' . $pkey . '" data-pkeyvalue="' . $item [$pkey] . '" data-value="' . $newVal . '" data-field="' . $key . '" href="' . zen_href_link($this->request->readGet('cmd'), zen_get_all_get_params(array(
                'action'
            )) . 'action=updateField&field=' . $key . '&value=' . $newVal) . '"><img border="0" title=" Status - Enabled " alt="Status - Enabled" src="images/' . $icon . '" ></a>';
    }
    /**
     *
     * @param unknown $item
     * @param unknown $key
     * @param unknown $pkey
     * @return string
     */
    public function zoneStatusIcon($item, $key, $pkey)
    {
        $sql = "SELECT count(*) AS num_zones FROM " . TABLE_ZONES_TO_GEO_ZONES . "  where geo_zone_id = '" . (int)$item['geo_zone_id'] . "'  group by geo_zone_id";
        $result = $this->dbConn->execute($sql);
        $sql = "select count(*) as num_tax_rates from " . TABLE_TAX_RATES . "  where tax_zone_id = '" . (int)$item['geo_zone_id'] . "'  group by tax_zone_id";
        $result1 = $this->dbConn->execute($sql);
        $icon = 'icon_status_red.gif';
        if ($result->fields['num_zones'] > 0) {
            $icon = 'icon_status_yellow.gif';
        }
        if ($result->fields['num_zones'] > 0 && $result1->fields['num_tax_rates'] > 0) {
            $icon = 'icon_status_green.gif';
        }
        return '<img border="0" title=" Status - Enabled " alt="Status - Enabled" src="images/' . $icon . '" >';
    }
}
