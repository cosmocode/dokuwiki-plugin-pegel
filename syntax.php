<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * DokuWiki Plugin pegel (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 */
class syntax_plugin_pegel extends SyntaxPlugin
{
    public const API_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2';

    protected $apiResults = [];

    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'normal';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 155;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<pegel .*?>', $mode, 'plugin_pegel');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 7, -1);
        [$uuid, $type] = sexplode(' ', $match, 2);
        $uuid = trim($uuid);
        $type = trim($type);
        if (!$type) $type = 'v';

        return [
            'uuid' => $uuid,
            'type' => $type,
        ];
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode === 'metadata') {
            return false;
        }
        // for everything else we simply use cdata()

        try {
            $pegel = $this->callAPI($data['uuid']);

            $station = $pegel[0]['stations'][0];
            $current = $station['timeseries'][0];

            switch ($data['type']) {
                case 'v':
                    $text = $current['currentMeasurement']['value'] . $current['unit'];
                    break;
                case 'dt':
                    $text = (new DateTime($current['currentMeasurement']['timestamp']))->format('Y-m-d H:i:s');
                    break;
                case 't':
                    $text = (new DateTime($current['currentMeasurement']['timestamp']))->format('H:i:s');
                    break;
                case 'd':
                    $text = (new DateTime($current['currentMeasurement']['timestamp']))->format('Y-m-d');
                    break;
                default:
                    $text = $station[$data['type']] ?? 'unknown type';
            }
        } catch (Exception $e) {
            $text = 'Error: ' . $e->getMessage();
        }

        $renderer->cdata($text);
        return true;
    }

    /**
     * Fetch the data from the API
     *
     * @param string $uuid
     * @return mixed
     * @throws Exception
     */
    protected function callAPI($uuid)
    {
        if (isset($this->apiResults[$uuid])) return $this->apiResults[$uuid]; // only one call per request

        $url = self::API_URL . '/waters.json?' . http_build_query(
                [
                    'stations' => $uuid,
                    'includeTimeseries' => 'true',
                    'includeCurrentMeasurement' => 'true',
                    'includeStations' => 'true',
                ],
                '', '&'
            );


        $http = new DokuHTTPClient();
        $ok = $http->sendRequest($url);
        if ($ok === false) {
            return throw new Exception('API request failed ' . $http->error, $http->status);
        }

        $data = json_decode($http->resp_body, true);
        $this->apiResults[$uuid] = $data;
        return $data;
    }
}
