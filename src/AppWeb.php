<?php
/**
 * @file
 * Class for creating facets using a single database table.
 */

namespace USDOJ\SingleTableFacets;

class AppWeb extends \USDOJ\SingleTableFacets\App {

    private $parameters;
    private $facets;
    private $display;
    private $userKeywords;
    private $dateGranularities;

    public function __construct($configFile) {

        $config = new \USDOJ\SingleTableFacets\Config($configFile);
        parent::__construct($config);

        $this->parameters = $this->parseQueryString();

        $uri_parts = explode('?', $_SERVER['REQUEST_URI'], 2);
        $this->baseUrl = $uri_parts[0];

        $this->setDateGranularities();

        // For now, there is only one type of display, but in the future we may
        // want to make this configurable.
        $this->display = new \USDOJ\SingleTableFacets\ResultDisplayTable($this);
    }

    public function getDisplay() {
        return $this->display;
    }

    public function getBaseUrl() {
        return $this->baseUrl;
    }

    public function getExtraParameters() {
        return array('keys', 'sort', 'sort_direction', 'page', 'full_text');
    }

    private function getFacetColumns() {
        $facets = $this->settings('facet labels');
        return array_keys($facets);
    }

    private function getAllowedParameters() {
        $extraParameters = $this->getExtraParameters();
        $facetColumnNames = $this->getFacetColumns();
        return array_merge($facetColumnNames, $extraParameters);
    }

    public function getParameter($param) {
        if (!empty($this->parameters[$param])) {
            return $this->parameters[$param];
        }
        return FALSE;
    }

    public function getParameters() {
        return $this->parameters;
    }

    public function getDateGranularities() {
        return $this->dateGranularities;
    }

    /**
     * Helper function to get the SQL for a full-text MATCH AGAINST query.
     */
    public function getMatchSQL() {
        $keywordColumns = $this->getKeywordColumns();
        $matchSQL = "MATCH($keywordColumns) AGAINST(:keywords IN BOOLEAN MODE)";
        return $matchSQL;
    }

    private function parseQueryString() {
        $params = $_GET;
        $currentQuery = array();
        $allowedParams = $this->getAllowedParameters();
        foreach ($allowedParams as $allowedParam) {
            if (!empty($params[$allowedParam])) {
                if (is_array($params[$allowedParam])) {
                    foreach ($params[$allowedParam] as $param) {
                        $currentQuery[$allowedParam][] = $param;
                    }
                }
                elseif (is_string($params[$allowedParam])) {
                    $currentQuery[$allowedParam] = $params[$allowedParam];
                }
            }
        }
        return $currentQuery;
    }

    public function renderKeywordSearch() {
        $searchBar = new \USDOJ\SingleTableFacets\SearchBar($this);
        return $searchBar->render();
    }

    public function renderFacets() {

        $output = '';
        foreach ($this->getFacetColumns() as $name) {
            $facet = new \USDOJ\SingleTableFacets\Facet($this, $name);
            $output .= $facet->render();
        }
        return $output;
    }

    public function renderResults() {
        return $this->getDisplay()->render();
    }

    public function renderPager() {
        return $this->getDisplay()->renderPager();
    }

    /**
    * Helper function to split a string into an array of space-delimited tokens
    * taking double-quoted and single-quoted strings into account.
    */
    public function tokenizeQuoted($string, $quotationMarks='"\'') {
        $tokens = array();
        for ($nextToken = strtok($string, ' '); $nextToken !== FALSE; $nextToken = strtok(' ')) {
            if (strpos($quotationMarks, $nextToken[0]) !== FALSE) {
                if (strpos($quotationMarks, $nextToken[strlen($nextToken)-1]) !== FALSE) {
                    $tokens[] = substr($nextToken, 1, -1);
                }
                else {
                    $tokens[] = '"' . substr($nextToken, 1) . ' ' . strtok($nextToken[0]) . '"';
                }
            }
            else {
                $tokens[] = $nextToken;
            }
        }
        return $tokens;
    }

    public function renderJavascript() {
        $location = $this->settings('location of assets');
        return '<script type="text/javascript" src="' . $location . '/singletablefacets.js"></script>';
    }

    public function renderStyles() {
        $location = $this->settings('location of assets');
        return '<link rel="stylesheet" href="' . $location . '/singletablefacets.css" />';
    }

    public function getUserKeywords() {

        if (!empty($this->userKeywords)) {
            return $this->userKeywords;
        }

        $keywords = $this->getParameter('keys');
        $tokenized = $this->tokenizeQuoted($keywords);
        if ($this->settings('use AND for keyword logic by default')) {
            $ors = array();
            foreach ($tokenized as $index => $value) {
                if ('OR' == $value || 'or' == $value) {
                    $ors[] = $index;
                }
            }
            $addPlus = TRUE;
            foreach ($tokenized as $index => &$value) {
                if (in_array($index, $ors)) {
                    $value = '';
                    $addPlus = FALSE;
                    continue;
                }
                if ($addPlus) {
                    $otherOperators = '-~<>+';
                    if (strpos($otherOperators, substr($value, 0, 1)) === FALSE) {
                        $value = '+' . $value;
                    }
                }
                $addPlus = TRUE;
            }
            $tokenized = array_filter($tokenized);
            $keywords = implode(' ', $tokenized);
        }
        if ($this->settings('automatically put wildcards on keywords entered')) {
            foreach ($tokenized as &$value) {
                $otherOperators = '"\'*)';
                if (strpos($otherOperators, substr($value, -1)) === FALSE) {
                    $value = $value . '*';
                }
            }
            $keywords = implode(' ' , $tokenized);
        }

        $this->userKeywords = $keywords;
        return $keywords;
    }

    public function query() {

        $query = parent::query();
        $query->from($this->settings('database table'));

        // Keywords are handled by MySQL, mostly.
        $keywords = $this->getUserKeywords();
        if (!empty($keywords)) {

            $matchSQL = $this->getMatchSQL();
            $query->andWhere($matchSQL);
            $query->setParameter('keywords', $keywords);
        }

        // Add conditions for the facets. At this point, we consult the full query
        // string, minus any of our "extra" params.
        $parsedQueryString = $this->getParameters();
        foreach ($this->getExtraParameters() as $extraParameter) {
            unset($parsedQueryString[$extraParameter]);
        }
        if (!empty($parsedQueryString)) {
            $dateColumns = $this->settings('date formats');
            $additionalColumns = $this->settings('columns for additional values');
            foreach ($parsedQueryString as $facetName => $facetItemValues) {

                // Check to see if we need to include additional columns.
                $columnsToCheck = array($facetName);
                if (!empty($additionalColumns)) {
                    foreach ($additionalColumns as $additionalColumn => $mainColumn) {
                        if ($facetName == $mainColumn) {
                            $columnsToCheck[] = $additionalColumn;
                        }
                    }
                }

                // Create an AND statement to construct our WHERE for the facet.
                $facetWhere = $query->expr()->andX();

                // Date facets are unique in that they will have only a single
                // value that we interpret into a hierarchical display. This is
                // no way, for example, for a date facet to be both "2011" and
                // "2012", or both "2012-01" and "2012-02". Once you select a
                // year, month, or day, all the other years/months/days will
                // disappear. Since they are unusal, handle them first.
                if (!empty($dateColumns[$facetName])) {
                    // Date facets are essentially ranges, so we need to query
                    // a range of dates.
                    foreach ($facetItemValues as $facetItemValue) {
                        $start = '';
                        $end = '';
                        // Each value can either be:
                        // - YYYY
                        // - YYYY-MM
                        // - YYYY-MM-DD
                        // So we can easily figure it out by the number of
                        // characters.
                        $numChars = strlen($facetItemValue);
                        if (4 == $numChars) {
                            // Year range.
                            $start = $facetItemValue . '-01-01 00:00:00';
                            $end = $facetItemValue . '-12-31 23:59:59';
                        }
                        elseif (7 == $numChars) {
                            // Month range.
                            $start = $facetItemValue . '-01 00:00:00';
                            $end = $facetItemValue . '-31 23:59:59';
                        }
                        elseif (10 == $numChars) {
                            $start = $facetItemValue . ' 00:00:00';
                            $end = $facetItemValue . ' 23:59:59';
                        }
                        else {
                            // Bad parameter, just skip it.
                            continue;
                        }

                        $startPlaceholder = $query->createNamedParameter($start);
                        $endPlaceholder = $query->createNamedParameter($end);
                        $dateOr = $query->expr()->orX();
                        foreach ($columnsToCheck as $columnToCheck) {
                            $dateOr->add("$columnToCheck BETWEEN $startPlaceholder AND $endPlaceholder");
                        }
                        $facetWhere->add($dateOr);
                    }
                }
                // Otherwise, non-date facets act completely differently.
                else {
                    foreach ($facetItemValues as $facetItemValue) {
                        $placeholder = $query->createNamedParameter($facetItemValue);
                        $columnsToCheckString = implode(',', $columnsToCheck);
                        $facetWhere->add("$placeholder IN ($columnsToCheckString)");
                    }
                }

                // Add the facet selects to the query.
                $query->andWhere($facetWhere);
            }
        }
        // Add conditions for any required columns.
        foreach ($this->settings('required columns') as $column) {
            $query->andWhere("($column <> '' AND $column IS NOT NULL)");
        }
        return $query;
    }

    public function getLink($url, $label, $query, $class) {

        $href = $this->getHref($url, $query);
        return sprintf('<a href="%s" class="%s">%s</a>', $href, $class, $label);
    }

    public function getHref($url, $query) {
        $href = $url;
        $query_string = http_build_query($query);
        if (!empty($query_string)) {
            $href .= '?' . $query_string;
        }
        return $href;
    }

    public function setDateGranularities() {
        // These are the possible date granularies:
        //   - year
        //   - year + month
        //   - year + month + day
        //   - month
        //   - month + day
        //   - day
        // The keys below are weirdly keyed to enforce a certain sort later.
        $dateFormatTokens = array(
            '1year' => array('Y', 'y', 'o'),
            '2month' => array('F', 'm', 'M', 'n'),
            '3day' => array('d', 'j'),
        );
        $granularities = array();

        $dateColumns = $this->settings('date formats');
        foreach ($dateColumns as $column => $format) {
            foreach ($dateFormatTokens as $granularity => $tokens) {
                foreach ($tokens as $token) {
                    if (strpos($format, $token) !== FALSE) {
                        $granularities[$column][] = $granularity;
                        break;
                    }
                }
            }
        }

        foreach ($granularities as $column => &$columnGranularities) {
            // Easy sort because year/month/day is naturally reverse alpha.
            sort($columnGranularities);
        }

        $this->dateGranularities = $granularities;
    }
}
