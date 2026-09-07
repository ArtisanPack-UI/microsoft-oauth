<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'microsoft_connections', function ( Blueprint $table ): void {
            $table->id();
            $table->unsignedBigInteger( 'user_id' );
            $table->string( 'microsoft_user_id' )->nullable();
            $table->string( 'email' )->nullable();
            $table->longText( 'access_token' )->nullable();
            $table->longText( 'refresh_token' )->nullable();
            $table->string( 'token_type' )->default( 'Bearer' );
            $table->text( 'scopes' )->nullable();
            $table->timestamp( 'expires_at' )->nullable();
            $table->string( 'status' )->default( 'connected' );
            $table->text( 'disconnect_reason' )->nullable();
            $table->timestamps();

            $table->unique( 'user_id' );
            $table->index( [ 'user_id', 'status' ] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'microsoft_connections' );
    }
};
