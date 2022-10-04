<?php
Route::group(['namespace'=>'Yarm\Elasticsearch\Http\Controllers','prefix'=> strtolower(config('yarm.sys_name')),'middleware'=>['web']], function (){

});
