<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'microsoft_oauth_configurations', function ( Blueprint $table ): void {
            $table->id();
            // Deterministic singleton key: exactly one credential row is
            // expected per application. The unique index prevents concurrent
            // initial saves from racing in a second row, and `updateOrInsert`
            // against this key gives us an atomic upsert.
            $table->string( 'singleton_key' )->unique()->default( 'default' );
            $table->string( 'client_id' )->nullable();
            $table->text( 'client_secret' )->nullable();
            $table->string( 'tenant' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'microsoft_oauth_configurations' );
    }
};
