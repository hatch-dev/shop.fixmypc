{{--
  The single-page app's shell.

  This used to be `include app_path() . './../index.html'`, with a commented
  out second line for VPS deployments. There is no separator between
  app_path() and the './..', so the path it built was `<base>/app./../index.html`
  -- a directory called `app.` -- and the route answered 500 on every
  environment. It went unnoticed because a real deployment never reaches it:
  Apache's DirectoryIndex serves index.html itself, and Laravel only sees `/`
  when the SPA shell is missing, which is exactly when the 500 is least
  helpful.

  Both layouts are now tried in turn -- the web root for cPanel, public/ for
  artisan serve and VPS -- so neither has to be commented in by hand.
--}}
@php
    $shell = collect([base_path('index.html'), public_path('index.html')])
        ->first(fn ($path) => is_file($path) && is_readable($path));
@endphp

@if ($shell)
    {{--
      readfile, not include: the shell is a generated static file and must not
      be executed as PHP. Anything that looked like a PHP tag inside it used to
      run.
    --}}
    @php readfile($shell) @endphp
@else
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Application shell not found</title>
    </head>
    <body>
        <h1>Application shell not found</h1>
        <p>
            The front end has not been deployed to this installation. Build it
            and copy <code>index.html</code> and <code>_nuxt/</code> to either
            the project root or <code>public/</code>.
        </p>
    </body>
    </html>
@endif
