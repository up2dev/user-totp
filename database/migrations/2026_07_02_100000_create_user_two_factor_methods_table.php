<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace l'ancienne table user_totp (mono-méthode) par une table
 * générique supportant plusieurs méthodes de double authentification par
 * utilisateur (totp, email, et d'autres plus tard).
 *
 * Toujours pas de contrainte de clé étrangère explicite vers "users" : le
 * package ignore volontairement le nom réel de la table de l'host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_two_factor_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('method', 20); // 'totp' | 'email'
            $table->text('secret')->nullable(); // utilisé par 'totp' uniquement
            $table->boolean('enabled')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor_methods');
    }
};
