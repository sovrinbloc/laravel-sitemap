<?php

namespace Spatie\Sitemap;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Spatie\Crawler\Crawler;
use Spatie\Sitemap\Tags\Tag;
use Spatie\Sitemap\Tags\Url;

const FORWARD_SLASH = '/';
class Sitemap implements Responsable
{
    /** @var bool */
    protected $deleteDuplicates;

    /** @var bool */
    protected $deleteHttp;

    /** @var bool */
    protected $addTrailingSlash;

    public function setDeleteHttp(bool $enabled)
    {
        $this->deleteHttp = $enabled;
    }

    public function setDeleteDuplicates(bool $enabled)
    {
        $this->deleteDuplicates = $enabled;
    }

    public function addTrailingSlash(bool $enabled)
    {
        $this->addTrailingSlash = $enabled;
    }

    /** @var array */
    protected $tags = [];

    public static function create(): self
    {
        return new static();
    }

    /**
     * @param string|\Spatie\Sitemap\Tags\Tag $tag
     *
     * @return $this
     */
    public function add($tag): self
    {
        if (is_string($tag)) {
            $tag = Url::create($tag);
        }

        if (!in_array($tag, $this->tags)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getUrl(string $url): ?Url
    {
        return collect($this->tags)->first(function (Tag $tag) use ($url) {
            return $tag->getType() === 'url' && $tag->url === $url;
        });
    }

    public function hasUrl(string $url): bool
    {
        return (bool)$this->getUrl($url);
    }

    public function omitUrls(array $urls): self
    {
        $this->tags = collect($this->tags)->reject(function (Tag $tag) use ($urls) {
            if (!$tag->getType() === 'url') {
                return false;
            }
            // remove https from url
            $urlWithoutHttp = str_replace('https://', '', $tag->url);
            $urlWithoutHttp = str_replace('http://', '', $urlWithoutHttp);
            if ($this->addTrailingSlash) {
                $urlWithoutHttp = rtrim($urlWithoutHttp, FORWARD_SLASH) . FORWARD_SLASH;
                // add trailing slash to urls that don't have one
                foreach ($urls as $key => $url) {
                    $urls[$key] = rtrim($url, FORWARD_SLASH) . FORWARD_SLASH;
                }
            } else {
                $urlWithoutHttp = rtrim($urlWithoutHttp, FORWARD_SLASH);
                foreach ($urls as $key => $url) {
                    $urls[$key] = rtrim($url, FORWARD_SLASH);
                }
            }
            return in_array($urlWithoutHttp, $urls);
        })->toArray();

        return $this;
    }

    public function render(): string
    {
        sort($this->tags);
        if ($this->deleteDuplicates) {
            foreach ($this->tags as $key => $tag) {
                if (!$this->addTrailingSlash) {
                    $tag->url = rtrim($tag->url, '/');
                } else {
                    if (substr($tag->url, -1) !== '/') {
                        $tag->url .= '/';
                    }
                }
            }
            $this->tags = array_unique($this->tags, SORT_REGULAR);
        }
        $tags = collect($this->tags)->unique('url');

        // delete entries with http://
        $tags = $tags->filter(function (Tag $tag) {
            if ($this->deleteHttp && strpos($tag->url, 'http:') === 0) {
                return false;
            }
            return true;
        });

        return view('laravel-sitemap::sitemap')
            ->with(compact('tags'))
            ->render();
    }

    public function writeToFile(string $path): self
    {
        file_put_contents($path, $this->render());

        return $this;
    }

    public function writeToDisk(string $disk, string $path): self
    {
        Storage::disk($disk)->put($path, $this->render());

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        return Response::make($this->render(), 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
