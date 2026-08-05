<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StoreSupportGuideArticleRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreSupportGuideCategoryRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateSupportGuideArticleRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateSupportGuideCategoryRequest;
use App\Http\Resources\Api\V1\SuperAdmin\SupportGuideArticleResource;
use App\Http\Resources\Api\V1\SuperAdmin\SupportGuideCategoryResource;
use App\Models\SupportGuideArticle;
use App\Models\SupportGuideCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupportGuideController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupportGuideCategoryResource::collection(
            SupportGuideCategory::ordered()->with(['articles' => fn ($q) => $q->ordered()])->get()
        );
    }

    public function storeCategory(StoreSupportGuideCategoryRequest $request): SupportGuideCategoryResource
    {
        return new SupportGuideCategoryResource(SupportGuideCategory::create($request->validated()));
    }

    public function updateCategory(UpdateSupportGuideCategoryRequest $request, SupportGuideCategory $category): SupportGuideCategoryResource
    {
        $category->update($request->validated());

        return new SupportGuideCategoryResource($category->fresh());
    }

    public function destroyCategory(SupportGuideCategory $category): Response
    {
        $category->delete();

        return response()->noContent();
    }

    public function storeArticle(StoreSupportGuideArticleRequest $request): SupportGuideArticleResource
    {
        return new SupportGuideArticleResource(SupportGuideArticle::create($request->validated()));
    }

    public function updateArticle(UpdateSupportGuideArticleRequest $request, SupportGuideArticle $article): SupportGuideArticleResource
    {
        $article->update($request->validated());

        return new SupportGuideArticleResource($article->fresh());
    }

    public function destroyArticle(SupportGuideArticle $article): Response
    {
        $article->delete();

        return response()->noContent();
    }
}
