<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFreshdeskImportTables extends Migration
{
    public function up()
    {
        // Freshdesk ticket id -> FreeScout conversation id (resume, sync and re-run without duplicates)
        Schema::create('freshdesk_import_tickets', function (Blueprint $table) {
            $table->bigInteger('fd_ticket_id')->unsigned()->primary();
            $table->integer('conversation_id')->unsigned()->index();
            $table->string('fd_updated_at', 20)->nullable(); // UTC "Y-m-d H:i:s" as text: a timestamp column would be shifted by the connection timezone
            $table->timestamp('imported_at')->nullable();
        });
        // Freshdesk agent id -> FreeScout user id
        Schema::create('freshdesk_import_agents', function (Blueprint $table) {
            $table->bigInteger('fd_agent_id')->unsigned()->primary();
            $table->integer('user_id')->unsigned()->nullable();
            $table->string('name', 191)->nullable();
            $table->string('email', 191)->nullable();
        });
        Schema::create('freshdesk_import_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('level', 10)->default('info');
            $table->text('message');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('freshdesk_import_logs');
        Schema::dropIfExists('freshdesk_import_agents');
        Schema::dropIfExists('freshdesk_import_tickets');
    }
}
