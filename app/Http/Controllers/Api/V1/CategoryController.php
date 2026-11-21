<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        return CategoryResource::collection(Category::query()->orderBy('name')->get());
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        return (new CategoryResource($this->master->saveCategory($request->validated())))->response()->setStatusCode(201);
    }
}
