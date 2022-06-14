<?php

namespace Yarm\Elasticsearch\App\Models;

use App\Http\Controllers\BookshelfBaseController;
use App\Http\Controllers\ExportController;
use App\Models\File;
use App\Models\Option;
use Illuminate\Database\Eloquent\Model;
use function App\Models\Auth;
use function App\Models\view;


class Elasticsearch extends Model
{

    private $clientBuilder;
    private $client;

    public function __construct()
    {
        $this->clientBuilder = \Elasticsearch\ClientBuilder::create();
        $this->client = $this->clientBuilder->build();

        //Todo move pipeline to migrations!
        //TODO: remove if elasticsearch migration proved to be working

        //create pipeline for upload files
        $params = [
            'id' => 'attachment',
            'body' => [
                'description' => 'Extract attachment information',
                'processors' => [
                    [
                        'attachment' => [
                            'field' => 'content',
                            'indexed_chars' => -1
                        ]
                    ]
                ]
            ]
        ];
        $this->client->ingest()->putPipeline($params);

    }

    // TODO all $index should use env('ELASTICSEARCH_INDEX') also do other functions below ...
    public function search($index, $type, $query, $request)//Todo use array for terms and create multiple terms
    {

        $i = 0;
        foreach ($request['field0'] as $field) {

            if (strtoupper($request['criterium0'][$i]) == 'LIKE')
                $search = "*" . $request['search0'][$i] . "*";
            else
                $search = $request['search0'][$i];

            //ToDo make function for year between (>xxx <yyy) - at the moment we respond with error
            if ($field == 'year' && strpos($request['search0'][$i], '-') !== false) {
                $result['error'] = 'Search in Full Text Error: Search on Year (between) not allowed';
                return $result;
            }


            //don't look for excluded terms
            if (strtoupper($request['criterium0'][$i]) == 'LIKE' or $request['criterium0'][$i] == '=') {
                if ($field == 'title') {
                    $searchArray[] = array('query_string' => array(
                        "type" => 'phrase', "query" => $search, 'fields' => array(0 => 'attachment.content^5', 1 => 'keywords'),
                        //'boost' => 4
                    ));
                } else if (in_array($field, ['year', 'author', 'language_source', 'language_target', 'primary', 'all_fields'])) {
                    if (($field = 'all_fields')) $field = 'citation';
                    $searchArray[] = array('query_string' => array(
                        "type" => 'phrase', "query" => $search, 'fields' => array(0 => $field),
                        'boost' => 3,
                    ));

                } else {
                    //Todo
                    // this is not the right way - maybe add roles to ES else skip???
                    $searchArray[] = array('query_string' => array(
                        "type" => 'phrase', "query" => $search, 'fields' => array(0 => 'citation'),
                        'boost' => 2,
                    ));
                }


            }
            $i++;
        }
        //Todo reindex primary in Elasticsearch
        //Todo reindex files not found!
        //Search on citation?? Why should we? See line 62

        //max. = 5000 Todo set max in Options or .env (validation)

        if (Auth()->user()) {
            $options = Option::where('user_id', '=', Auth()->user()->id)->first();
            $maxElastic = $options['max_elastic'];
        }
        else
            $maxElastic = 20;

        //$term = $request['field0'][array_search('title',$request['field0'])];


        $params = [
            '_source_includes' => ["fileName", "refId", "author"], //export only 'fileName'
            'index' => $index,
            //'from' => 20,
            'size' => $maxElastic,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $searchArray,
                    ]
                ],
                'sort' => [
                    ['_score' => ['order' => 'desc']]],
                'collapse' => ['field' => 'refId']

            ]
        ];
        try {
            $result = $this->client->search($params);

        } catch (\Throwable $e) {
            //dd($e);
            $result['error'] = 'Search in Full Text: Bad Request';
            return $result;
        }

        if (count($result['hits']['hits']) > 0) {
            $i = 0;
            $orWhere = [];
            do {
                $orWhere[] = $result['hits']['hits'][$i]['_source']['refId'];
                $i++;
            } while ($i <= count($result['hits']['hits']) - 1);
            return $orWhere;
        } else {
            return false;
        }

    }

    public function searchID($index, $ids) //$ids is array of id's
    {

        $params = [
            '_source_includes' => ["year", "citation", "allFields", "author"], //export only 'id' and 'year'
            'index' => $index,
            'body' => [
                'query' => [
                    'terms' => [
                        '_id' => $ids,
                    ]
                ]
            ]
        ];
        $result = $this->client->search($params);
        return $result;
    }

    public function searchInAllFields($index, $string)
    {
        $string = '*' . $string . '*';

        $paramsSearch = [
            '_source_includes' => ["refId"],
            'index' => $index,
            'size' => 50,
            'body' => [
                'query' => [
                    'match' => [
                        'allFields' => $string,
                    ]
                ]
            ]
        ];

        try {
            $result = $this->client->search($paramsSearch);
            $idArray = [];
            if ($result['hits']['total']['value'] >= 1) {
                foreach ($result['hits']['hits'] as $hit) {
                    $idArray[] = $hit['_source']['refId'];
                }
            }
            return $idArray;

        } catch (\Throwable $e) {
            //dd($e);
            $result['error'] = 'Search in allFields: Error / Bad Request';
            return $result;
        }
    }

    public function searchRefID($index, $ref) //$ids is array of id's
    {
        $paramsSearch = [
            '_source_includes' => ["refId", "title", "primary", "language_target", "allFields", "refId", "year", "primary", "citation", "author"],
            'index' => $index,
            'body' => [
                'query' => [
                    'match' => [
                        'refId' => $ref->id,
                    ]
                ]
            ]
        ];
        return $this->client->search($paramsSearch);
    }

    public function updateESforRefID($presentInES, $index, $ref)
    {
        $i = 0;
        try {
            while ($i <= count($presentInES['hits']['hits']) - 1) {
                $id = $presentInES['hits']['hits'][$i]['_id'];
                $params = $this->makeDataAndParams($index, $ref, $id);
                $this->client->update($params);
                $i++;
            }
            return true;
        } catch (\Throwable $e) {
            //dd($e);
            return $e;
        }
    }

    public function createAllfields($ref) {
        $dataSet = $ref->prepareDataset();
        $data['ref'] = $ref;

        $unwanted = ['creator', 'modifier', 'modifier_id', 'user_id', 'language_id',
            'types', 'specialRoles', 'showNoTypes', 'groups', 'created_at', 'updated_at', 'hash', 'no_dups', 'version',
            'language_target_id', 'language_source_id', 'old_parent_id', 'old_serial_id', 'type_id', 'genre_id',
            'id', 'status_id', 'parent_id'];

        foreach ($unwanted as $column) {
            unset($dataSet[$column]);
        }

        $allFields = json_encode($dataSet, JSON_UNESCAPED_UNICODE);
        return [$allFields,$dataSet];
    }

    public function makeDataAndParams($index, $ref, $id = null)
    {

        list($allFields,$dataSet) = $this->createAllfields($ref);
        $author = $dataSet['author'];
        $title = $dataSet['title'];
        $year = $dataSet['year'];
        $keywords = $dataSet['keywords'];
        $primary = $dataSet['primarytxt'];


        if (isset($dataSet['language_source']))
            $languageSource = $dataSet['language_source'];
        else
            $languageSource = 'Unknown';
        if (isset($dataSet['language_target']))
            $languageTarget = $dataSet['language_target'];
        else
            $languageTarget = 'Unknown';

        $type = $dataSet['type'];

        if ($keywords != '')
            $pos = strpos("secondary", $keywords);
        else
            $pos = false;

        if ($primary == '1' or $pos === false)
            $primary = 'Yes';
        else
            $primary = 'No';

        $citation = ExportController::reformatBladeExport(view('dlbt.styles.format_as_' . Style::getNameStyle(), $data)->render());

        if ($id == null) {
            $params = [
                'index' => $index,
                'id' => 'RefId_' . $ref->id,
                'type' => 'ref',
                'body' => [
                    'refId' => $ref->id,
                    'title' => $title,
                    'author' => $author,
                    'year' => $year,
                    'keywords' => $ref->keywords,
                    'primary' => $primary,
                    'citation' => $citation,
                    'language_source' => $languageSource,
                    'language_target' => $languageTarget,
                    'type' => $type,
                    'allFields' => $allFields
                ],
            ];
        } else {
            $params = [
                'index' => $index,
                'type' => 'ref',
                'id' => $id,
                'body' => [
                    'doc' => [
                        'allFields' => $allFields
                    ],
                ],
            ];
        }
        return $params;
    }

    public function storeNewESforRefId($index, $ref)
    {

        $params = $this->makeDataAndParams($index, $ref);

        try {
            $result = $this->client->index($params);
            return true;
        } catch (\Throwable $e) {
            //dd($e);
            return $e;
        }


    }

    public function createUpdateFile($index_name, $fileInfo, $fileName, $id, $refId, $author, $title, $year, $keywords, $primary, $citation, $languageSource, $languageTarget, $type,$allFields)
    {
        $file = FALSE;
        //$fileName = $fileName;

        if (file_exists($fileInfo)) {
            if (pathinfo($fileInfo, PATHINFO_EXTENSION) == 'pdf') {
                if (filesize($fileInfo) <= 48000000) {
                    $file = file_get_contents($fileInfo);
                    //check if pdf = readable
                    /*$readable = BookshelfController::checkIfPDFIsReadable($fileInfo);
                    if ($readable != false) {
                        $file = $readable;
                    } else {
                        return 'pdf not readable';
                    }*/
                } else {
                    return 'pdf to big';
                }
            } else {
                $file = file_get_contents($fileInfo);
            }

        }
        if ($file === FALSE) {
            return 'file not found';
        }

        $base64 = base64_encode($file);
        //clear memory
        unset($file);

        $params = [
            'index' => $index_name,
            'type' => 'ref',
            'id' => $id,
            'pipeline' => 'attachment',
            'body' => [
                'fileName' => $fileName,
                'refId' => $refId,
                'title' => $title,
                'author' => $author,
                'year' => $year,
                'keywords' => $keywords,
                'primary' => $primary,
                'citation' => $citation,
                'language_source' => $languageSource,
                'language_target' => $languageTarget,
                'type' => $type,
                'content' => $base64,
                'allFields' => $allFields
            ]
        ];

        //clear memory
        unset($base64);
        //echo (memory_get_peak_usage());
        try {
            $result = $this->client->index($params);
            $fileToSave = File::find($id);
            $fileToSave->esearch = 'yes';
            $fileToSave->save();
            unset($params['body']);
            return true;
        } catch (\exeption $e) {
            unset($params['body']);
            return 'error indexing file';
        }
    }

    public function deleteId($index, $type, $id)
    {
        $params = array();
        $params['index'] = $index;
        //$params['type'] = $type;
        $params['id'] = $id;

        $id = $this->client->exists($params);//Check ID first!!! (HVU) otherwise FATAL Error!
        if ($id === TRUE) {
            $result = $this->client->delete($params);
            if ($result['result'] == 'deleted')
                return 'TRUE';
            else
                return 'FALSE';
        } else
            return "FALSE";
    }

    /* public function showTermFreqOnYear($yStart,$yEnd,$query,$size)
     {
         $filters=array();
         $yearStart=$yStart;
         $yearEnd=$yEnd;

         if ($yearStart != $yearEnd) {
             while ($yearStart <= $yearEnd) {
                 $filters[$yearStart] = array('match' => array('year' => (string)$yearStart));
                 $yearStart++;
                 $must=array(array('query_string'=>array("query"=>$query,'fields'=>array(0=>'attachment.content'),'default_operator'=>'AND')));
                 $size=0;
             }
         }
         else {
             $filters[$yearStart] = array('match' => array('year' => (string)$yearStart));
             $must=array(array('query_string'=>array("query"=>$query,'fields'=>array(0=>'attachment.content'),'default_operator'=>'AND')),array('term'=>array('year'=>array('value'=>$yearStart,'boost'=>1))));
             $size=50;
         }


         $params = [
             '_source_includes' => ["year"], //export only 'id' and 'year'
             'index' => "dlbtsekdocs",
             //'type' => $type,
             'size' => $size,

             'body' => [
                 'query' => [
                     'bool' => [
                         'must' => $must
                     ]
                 ],
                 'aggs' => [
                     'Data' => [
                         "filters" => [
                             "filters" => $filters
                         ]
                     ]

                 ]
             ]
         ];

         $result = $this->client->search($params);
         return $result;
     }*/
}
