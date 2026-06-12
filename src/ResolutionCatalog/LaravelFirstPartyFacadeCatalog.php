<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Factory;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\SessionManager;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\MaintenanceMode;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\ParallelTesting;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;

/**
 * First-party Laravel facade → container resolution mappings.
 *
 * Sources: Laravel facade docs, rector-laravel static-to-injection set, framework accessors.
 */
final class LaravelFirstPartyFacadeCatalog
{
    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function entries(): array
    {
        return array_map(
            fn (array $row): ResolutionCatalogEntry => $this->entry(
                facadeClass: $row[0],
                abstract: $row[1],
                bindingKey: $row[2],
                bindsToType: $row[3],
            ),
            self::ROWS,
        );
    }

    /**
     * @return array<string, ResolutionCatalogEntry>
     */
    public function indexedByFacadeClass(): array
    {
        $indexed = [];

        foreach ($this->entries() as $entry) {
            if ($entry->facadeClass !== null) {
                $indexed[$entry->facadeClass] = $entry;
            }
        }

        return $indexed;
    }

    /**
     * @return list<class-string<Facade>>
     */
    public function facadeClasses(): array
    {
        return array_map(
            static fn (array $row): string => $row[0],
            self::ROWS,
        );
    }

    private function entry(
        string $facadeClass,
        string $abstract,
        ?string $bindingKey,
        BindsToType $bindsToType,
    ): ResolutionCatalogEntry {
        return new ResolutionCatalogEntry(
            identifier: $facadeClass,
            kind: ResolutionCatalogKind::Facade,
            abstract: $abstract,
            bindsToType: $bindsToType,
            source: ResolutionCatalogSource::LaravelFacade,
            bindingKey: $bindingKey,
            facadeClass: $facadeClass,
        );
    }

    /**
     * [facadeClass, abstract, bindingKey|null, bindsToType]
     *
     * @var list<array{0: class-string<Facade>, 1: string, 2: null|string, 3: BindsToType}>
     */
    private const ROWS = [
        [App::class, Application::class, 'app', BindsToType::Singleton],
        [Artisan::class, Kernel::class, 'artisan', BindsToType::Singleton],
        [Auth::class, AuthManager::class, 'auth', BindsToType::Singleton],
        [Blade::class, BladeCompiler::class, 'blade.compiler', BindsToType::Singleton],
        [Broadcast::class, \Illuminate\Contracts\Broadcasting\Factory::class, null, BindsToType::Singleton],
        [Bus::class, \Illuminate\Contracts\Bus\Dispatcher::class, null, BindsToType::Singleton],
        [Cache::class, CacheManager::class, 'cache', BindsToType::Singleton],
        [Concurrency::class, ConcurrencyManager::class, null, BindsToType::Singleton],
        [Config::class, Repository::class, 'config', BindsToType::Singleton],
        [Context::class, \Illuminate\Log\Context\Repository::class, null, BindsToType::Singleton],
        [Cookie::class, CookieJar::class, 'cookie', BindsToType::Singleton],
        [Crypt::class, Encrypter::class, 'encrypter', BindsToType::Singleton],
        [Date::class, DateFactory::class, 'date', BindsToType::Singleton],
        [DB::class, DatabaseManager::class, 'db', BindsToType::Singleton],
        [Event::class, Dispatcher::class, 'events', BindsToType::Singleton],
        [Exceptions::class, ExceptionHandler::class, null, BindsToType::Singleton],
        [File::class, Filesystem::class, 'files', BindsToType::Singleton],
        [Gate::class, \Illuminate\Contracts\Auth\Access\Gate::class, null, BindsToType::Singleton],
        [Hash::class, Hasher::class, 'hash', BindsToType::Singleton],
        [Http::class, \Illuminate\Http\Client\Factory::class, null, BindsToType::Singleton],
        [Lang::class, Translator::class, 'translator', BindsToType::Singleton],
        [Log::class, LogManager::class, 'log', BindsToType::Singleton],
        [Mail::class, Mailer::class, 'mail.manager', BindsToType::Singleton],
        [MaintenanceMode::class, MaintenanceModeManager::class, null, BindsToType::Singleton],
        [Notification::class, ChannelManager::class, null, BindsToType::Singleton],
        [\Illuminate\Support\Facades\ParallelTesting::class, ParallelTesting::class, null, BindsToType::Singleton],
        [Password::class, PasswordBrokerManager::class, 'auth.password', BindsToType::Singleton],
        [\Illuminate\Support\Facades\Pipeline::class, Pipeline::class, 'pipeline', BindsToType::Singleton],
        [Process::class, Factory::class, null, BindsToType::Singleton],
        [Queue::class, QueueManager::class, 'queue', BindsToType::Singleton],
        [\Illuminate\Support\Facades\RateLimiter::class, RateLimiter::class, null, BindsToType::Singleton],
        [Redirect::class, Redirector::class, 'redirect', BindsToType::Singleton],
        [Redis::class, RedisManager::class, 'redis', BindsToType::Singleton],
        [\Illuminate\Support\Facades\Request::class, Request::class, 'request', BindsToType::Singleton],
        [Response::class, ResponseFactory::class, null, BindsToType::Singleton],
        [Route::class, Router::class, 'router', BindsToType::Singleton],
        [Schedule::class, \Illuminate\Console\Scheduling\Schedule::class, null, BindsToType::Singleton],
        [Schema::class, Builder::class, 'db.schema', BindsToType::Singleton],
        [Session::class, SessionManager::class, 'session', BindsToType::Singleton],
        [Storage::class, FilesystemManager::class, 'filesystem', BindsToType::Singleton],
        [URL::class, UrlGenerator::class, 'url', BindsToType::Singleton],
        [Validator::class, \Illuminate\Validation\Factory::class, 'validator', BindsToType::Singleton],
        [View::class, \Illuminate\View\Factory::class, 'view', BindsToType::Singleton],
        [\Illuminate\Support\Facades\Vite::class, Vite::class, null, BindsToType::Singleton],
    ];
}
