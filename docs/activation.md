# Activation and access control

Lemonade Profiler is optional. Add `Lemonade\Profiler\ProfilerServiceProvider` to
`app/Config/Providers.yaml` to register it. There is no Composer discovery or
conditional `Providers.php` registration. The provider must be installed in any
deployment whose configuration references it.

`ProfilerConfig::enabled()` is the activation switch. It defaults to `true` once
the provider is registered. To turn the profiler off, bind a disabled config in
the root container before the first request:

```php
use Lemonade\Profiler\ProfilerConfig;

$container->singleton(ProfilerConfig::class, new ProfilerConfig(enabled: false));
```

When disabled, the profiler does not capture requests, add headers or toolbar
markup, or serve its endpoints. When enabled, it works with both development and
production `APP_ENV` values and either `APP_DEBUG` value. Its private storage,
bounded capture, and sanitization rules still apply.

For public production deployments, restrict `/_lemonade/profiler` and all of its
subpaths to authorized users before requests reach the application, such as at
the reverse proxy or a private network boundary. The profiler middleware serves
these URLs before application routing, so route-specific access rules may not
protect them. Configure access protection using your existing infrastructure;
the package does not add an authentication system.
