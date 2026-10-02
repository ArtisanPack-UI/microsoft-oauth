<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'microsoft_oauth_configurations', function ( Blueprint $table ): void {
            // Null falls back to `config('microsoft-oauth.redirect_uri')`.
            $table->string( 'redirect_uri' )->nullable()->after( 'tenant' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'microsoft_oauth_configurations', function ( Blueprint $table ): void {
            $table->dropColumn( 'redirect_uri' );
        } );
    }
};
