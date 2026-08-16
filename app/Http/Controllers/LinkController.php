<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkStoreLinkRequest;
use App\Http\Requests\StoreLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Services\ClickBuffer;
use App\Services\LinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LinkController extends Controller
{
    private const SORTABLE = ['created_at', 'click_count', 'last_clicked_at', 'slug'];

    public function __construct(
        private readonly LinkService $links,
        private readonly ClickBuffer $clicks,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? (string) $request->query('sort')
            : 'created_at';

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = Link::query()->search($request->query('q'));

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $paginator = $query->orderBy($sort, $direction)->paginate($perPage)->withQueryString();

        // One MGET for the whole page rather than a Redis round-trip per row.
        $live = $this->clicks->liveClicksFor($paginator->pluck('id')->all());

        return LinkResource::collection(
            $paginator->through(fn (Link $link) => new LinkResource($link, $live[$link->id] ?? 0))
        );
    }

    public function store(StoreLinkRequest $request): JsonResponse
    {
        $link = $this->links->create($request->validated());

        return (new LinkResource($link))->response()->setStatusCode(201);
    }

    public function bulkStore(BulkStoreLinkRequest $request): JsonResponse
    {
        $links = $this->links->createMany($request->validated()['links']);

        return LinkResource::collection($links)->response()->setStatusCode(201);
    }

    public function show(Link $link): LinkResource
    {
        return new LinkResource($link, $this->clicks->liveClicks($link->id));
    }

    public function update(UpdateLinkRequest $request, Link $link): LinkResource
    {
        $link = $this->links->update($link, $request->validated());

        return new LinkResource($link, $this->clicks->liveClicks($link->id));
    }

    public function destroy(Link $link): JsonResponse
    {
        $this->links->delete($link);

        return response()->json(null, 204);
    }
}
