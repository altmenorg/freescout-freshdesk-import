<?php

Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\FreshdeskImport\Http\Controllers'], function () {
    Route::post('/freshdesk-import/action', 'FreshdeskImportController@action')->name('freshdeskimport.action');
    Route::get('/freshdesk-import/status', 'FreshdeskImportController@status')->name('freshdeskimport.status');
});
