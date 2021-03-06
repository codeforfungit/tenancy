<?php

/*
 * This file is part of the tenancy/tenancy package.
 *
 * (c) Daniël Klabbers <daniel@klabbers.email>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see http://laravel-tenancy.com
 * @see https://github.com/tenancy
 */

namespace Tenancy\Identification;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Traits\Macroable;
use Tenancy\Identification\Contracts\Tenant;
use Tenancy\Identification\Contracts\ResolvesTenants;
use Tenancy\Identification\Support\TenantModelCollection;

class TenantResolver implements ResolvesTenants
{
    use Macroable;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * The tenant models.
     *
     * @var TenantModelCollection
     */
    protected $models;

    protected $drivers = [];

    public function __construct(Dispatcher $events)
    {
        $this->models = new TenantModelCollection();
        $this->events = $events;

        $this->configure();
    }

    public function __invoke(): ?Tenant
    {
        $tenant = $this->events->until(new Events\Resolving($models = $this->getModels()));

        if (! $tenant) {
            $tenant = $this->resolveFromDrivers($models);
        }

        if ($tenant) {
            $this->events->dispatch(new Events\Identified($tenant));
        }

        if (! $tenant) {
            $this->events->dispatch(new Events\NothingIdentified($tenant));
        }

        $this->events->dispatch(new Events\Resolved($tenant));

        return $tenant;
    }

    protected function configure()
    {
        $this->events->dispatch(new Events\Configuring($this));
    }

    public function addModel(string $class)
    {
        if (! in_array(Tenant::class, class_implements($class))) {
            throw new \InvalidArgumentException("$class has to implement " . Tenant::class);
        }

        $this->models->push($class);

        return $this;
    }

    public function getModels(): TenantModelCollection
    {
        return $this->models;
    }

    /**
     * Updates the tenant model collection.
     *
     * @param TenantModelCollection $collection
     * @return $this
     */
    public function setModels(TenantModelCollection $collection)
    {
        $this->models = $collection;

        return $this;
    }

    /**
     * @param string $contract
     * @param string $method
     * @return $this
     */
    public function registerDriver(string $contract, string $method)
    {
        $this->drivers[$contract] = $method;

        return $this;
    }

    /**
     * @param TenantModelCollection $models
     * @return Tenant
     */
    protected function resolveFromDrivers(TenantModelCollection $models): ?Tenant
    {
        $tenant = null;

        $models
            ->filterByContract(array_keys($this->drivers))
            ->each(function (string $item) use (&$tenant) {
                $implements = class_implements($item);
                $drivers    = array_intersect($implements, array_keys($this->drivers));

                foreach ($drivers as $driver) {
                    $method = $this->drivers[$driver];
                    $tenant = app()->call("$item@$method");

                    if ($tenant) {
                        return false;
                    }
                }
            });

        return $tenant;
    }
}
