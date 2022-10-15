<?php
Route::group(['namespace'=>'Yarm\Elasticsearch\Http\Controllers','prefix'=> strtolower(config('yarm.sys_name')),'middleware'=>['web']], function (){
//Route::get('upload2ElSearch', 'ElasticsearchController@upload2ElasticSearch');
Route::get('updateAllFieldsELSearch', 'ElasticsearchController@updateAllFieldsElasticSearch');
});
