<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('meta_description', 320);
            $table->string('h1');
            $table->string('category');          // comparison, alternative, guide, tax, industry, feature
            $table->json('keywords');             // targeted keyword phrases
            $table->text('excerpt');              // short intro for listing pages
            $table->longText('content');          // full HTML body content
            $table->json('faq_items')->nullable();   // [{question, answer}] for FAQ rich snippets
            $table->string('featured_image')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_published');
            $table->index(['is_published', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
