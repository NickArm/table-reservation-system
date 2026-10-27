{{--
    The sitemap (SPEC §33.2).

    A Blade file rather than a string built in PHP, so that the shape of the
    document is readable as a document. Nothing here is wrapped in a verbatim
    block: the two values interpolated are a URL we generated and a timestamp,
    and both still go through escaping.
--}}
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if (! empty($url['lastmod']))
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>
