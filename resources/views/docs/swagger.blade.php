<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LaraWA API Docs</title>
    @include('partials.favicons')
    @vite(['resources/js/swagger.js'])
</head>
<body>
    <div id="swagger-ui" data-openapi-url="{{ route('docs.openapi') }}"></div>
</body>
</html>
