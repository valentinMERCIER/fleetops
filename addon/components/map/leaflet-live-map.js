import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { guidFor } from '@ember/object/internals';
import { camelize, capitalize, dasherize } from '@ember/string';
import { singularize, pluralize } from 'ember-inflector';
import { all } from 'rsvp';
import { task } from 'ember-concurrency';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class MapLeafletLiveMapComponent extends Component {
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service leafletContextmenuManager;
    @service resourceContextPanel;
    @service serviceAreaActions;
    @service zoneActions;
    @service placeActions;
    @service vehicleActions;
    @service driverActions;
    @service movementTracker;
    @service geofence;
    @service location;
    @service fetch;
    @service abilities;
    @service intl;
    @service universe;

    /** properties */
    id = guidFor(this);

    /** tracked properties */
    @tracked ready = false;
    @tracked failed = false;
    @tracked retryCount = 0;
    @tracked zoom = this.args.zoom ?? 14;
    @tracked latitude = this.args.latitude ?? this.getDefaultLatitude();
    @tracked longitude = this.args.longitude ?? this.getDefaultLongitude();
    @tracked contextmenuItems = [];
    @tracked tileUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
    @tracked theme = 'light';
    @tracked routes = [];
    @tracked drivers = [];
    @tracked vehicles = [];
    @tracked places = [];

    /** private properties */
    _fallbackTimeout = null;
    _loadAttempted = false;

    @action async didLoad({ target: map }) {
        debug('Map didLoad called, map loaded:', map._loaded, 'container dimensions:', map.getContainer()?.offsetWidth, 'x', map.getContainer()?.offsetHeight);

        this.#setMap(map);
        this.#createMapContextMenu(map);
        this.trigger('onLoad', ...arguments);

        // Start loading resources immediately - no need to wait for location
        this.#startLoadSequence();
    }

    @action trigger(name, ...rest) {
        if (typeof this[name] === 'function') {
            this[name](...rest);
        }
        if (typeof this.args[name] === 'function') {
            this.args[name](...rest);
        }
        // Fire as universe event
        const uevent = dasherize(name);
        this.universe.trigger(`fleet-ops.live-map.${uevent}`, ...rest);
    }

    @action didCreateDrawControl(drawControl) {
        this.leafletMapManager.setDrawControl(drawControl);
        this.trigger('onDrawControlCreated', ...arguments);
    }

    @action didCreateDrawControlFeatureGroup(featureGroup) {
        this.leafletMapManager.setDrawControlFeatureGroup(featureGroup);
        this.trigger('onDrawFeatureGroupCreated', ...arguments);
    }

    @action onDriverAdded(driver, { target: layer }) {
        this.#setResourceLayer(driver, layer);
        this.#createDriverContextMenu(driver, layer);
        this.movementTracker.track(driver);
    }

    @action onDriverClicked(driver) {
        this.driverActions.panel.view(driver, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onVehicleAdded(vehicle, { target: layer }) {
        this.#setResourceLayer(vehicle, layer);
        this.#createVehicleContextMenu(vehicle, layer);
        this.movementTracker.track(vehicle);
    }

    @action onVehicleClicked(vehicle) {
        this.vehicleActions.panel.view(vehicle, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onPlaceAdded(place, { target: layer }) {
        this.#setResourceLayer(place, layer);
    }

    @action onPlaceClicked(place) {
        this.placeActions.panel.view(place, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onServiceAreaLayerAdded(serviceArea, { target: layer }) {
        this.#setResourceLayer(serviceArea, layer, { hidden: true });
        this.#createServiceAreaContextMenu(serviceArea, layer);
    }

    @action onZoneLayerAdd(zone, { target: layer }) {
        this.#setResourceLayer(zone, layer, { hidden: true });
        this.#createZoneContextMenu(zone, layer);
    }

    /** load resources and wait for stuff here and trigger map ready **/
    @task *load() {
        try {
            debug('Starting to load live map resources...');

            // Additional validation to ensure map is ready for resource loading
            if (!this.map || !this.map._loaded) {
                debug('Map not properly loaded, waiting additional 500ms...');
                yield new Promise((resolve) => setTimeout(resolve, 500));
            }

            // Validate container is properly sized and visible
            const container = this.map.getContainer();
            if (container && (container.offsetWidth === 0 || container.offsetHeight === 0)) {
                debug('Map container has zero dimensions, invalidating size...');
                this.map.invalidateSize();
                yield new Promise((resolve) => setTimeout(resolve, 200));
            }

            // Final validation before proceeding
            if (!this.map) {
                throw new Error('Map is null after waiting');
            }

            // Load resources with individual error handling to prevent total failure
            const loadPromises = [
                this.loadResource.perform('routes').catch((err) => {
                    debug('Routes failed:', err.message);
                    return [];
                }),
                this.loadResource.perform('vehicles').catch((err) => {
                    debug('Vehicles failed:', err.message);
                    return [];
                }),
                this.loadResource.perform('drivers').catch((err) => {
                    debug('Drivers failed:', err.message);
                    return [];
                }),
                this.loadResource.perform('places').catch((err) => {
                    debug('Places failed:', err.message);
                    return [];
                }),
                this.loadResource.perform('service-areas').catch((err) => {
                    debug('Service areas failed:', err.message);
                    return [];
                }),
            ];

            debug('All resource loading promises created, waiting...');
            const data = yield all(loadPromises);
            debug('All resources loaded successfully');

            this.#createMapContextMenu(this.map);

            // Try to center map based on loaded data with fallback logic
            yield this.#centerMapOnData(data);

            this.trigger('onLoaded', { map: this.map, data });
            this.ready = true;
            this.#clearFallbackTimeout();
            debug('Live map fully ready');
        } catch (err) {
            debug('Failed to load live map: ' + err.message);
            // Still mark as ready even if some resources failed to load
            // This prevents the map from being stuck in loading state
            this.ready = true;
            this.#clearFallbackTimeout();
        }
    }

    @task *loadResource(path, options = {}) {
        if (this.abilities.cannot(`fleet-ops list ${path}`)) return [];

        if (path === 'service-areas') {
            try {
                const serviceAreas = yield this.serviceAreaActions.loadAll.perform();
                this.trigger('onServiceAreasLoaded', serviceAreas);
                return serviceAreas;
            } catch (err) {
                debug(`Failed to load service areas: ${err.message}`);
                return [];
            }
        }

        const name = camelize(path);
        const callback = `on${capitalize(name)}Loaded`;
        const params = options.params ?? {};
        const url = `fleet-ops/live/${path}`;

        try {
            const data = yield this.fetch.get(url, params, {
                normalizeToEmberData: true,
                normalizeModelType: singularize(dasherize(name)),
                timeout: 10000, // 10 second timeout for individual resource loading
            });

            this.trigger(callback, data);
            this[name] = data;

            if (typeof options.onLoaded === 'function') {
                options.onLoaded(data);
            }

            return data;
        } catch (err) {
            debug(`Failed to load resource '${path}': ${err.message}`);
            // Return empty array instead of undefined for failed resources
            const emptyData = [];
            this.trigger(callback, emptyData);
            this[name] = emptyData;

            if (typeof options.onFailure === 'function') {
                options.onFailure(err);
            }

            return emptyData;
        }
    }

    isReady() {
        return this.ready === true;
    }

    /**
     * Get default latitude for map fallback
     * Priority: args.defaultLatitude → Singapore default (1.369)
     */
    getDefaultLatitude() {
        return this.args.defaultLatitude ?? 1.369; // Singapore coordinates as final fallback
    }

    /**
     * Get default longitude for map fallback  
     * Priority: args.defaultLongitude → Singapore default (103.8864)
     */
    getDefaultLongitude() {
        return this.args.defaultLongitude ?? 103.8864; // Singapore coordinates as final fallback
    }

    @action retryMapLoad() {
        debug('Retrying map load...');
        this.retryCount++;
        this.ready = false;
        this.failed = false;
        this._loadAttempted = false;
        this.#clearFallbackTimeout();

        if (this.map) {
            this.#startLoadSequence();
        } else {
            debug('No map available for retry');
        }
    }

    async #updateMapLocationAsFallback() {
        try {
            debug('Priority 2: Attempting to get browser location as fallback...');
            
            // 5 second timeout for fallback geolocation
            const locationPromise = this.location.getUserLocation();
            const timeoutPromise = new Promise((resolve) => {
                setTimeout(() => resolve(null), 5000);
            });
            
            const location = await Promise.race([locationPromise, timeoutPromise]);

            if (location && location.latitude && location.longitude) {
                debug('Priority 2: Got browser location as fallback:', location.latitude, location.longitude);
                this.latitude = location.latitude;
                this.longitude = location.longitude;

                // Update map center if it's available
                if (this.map && this.map.setView) {
                    this.map.setView([location.latitude, location.longitude], this.zoom);
                    debug('Priority 2: Map centered on browser location');
                }
            } else {
                debug('Priority 3: No browser location available, will use Singapore defaults from template');
            }
        } catch (error) {
            debug('Priority 3: Failed to get browser location, will use Singapore defaults:', error.message);
        }
    }

    async #centerMapOnData(data) {
        try {
            // Priority 1: Try to center on data first (vehicles, drivers, places)
            const [, vehicles, drivers, places] = data || [];
            const allPoints = [];

            // Collect coordinates from vehicles and drivers
            if (vehicles && vehicles.length > 0) {
                vehicles.forEach((vehicle) => {
                    if (vehicle.location && vehicle.location.coordinates) {
                        const [lng, lat] = vehicle.location.coordinates;
                        if (lat && lng) allPoints.push([lat, lng]);
                    }
                });
            }

            if (drivers && drivers.length > 0) {
                drivers.forEach((driver) => {
                    if (driver.location && driver.location.coordinates) {
                        const [lng, lat] = driver.location.coordinates;
                        if (lat && lng) allPoints.push([lat, lng]);
                    }
                });
            }

            if (places && places.length > 0) {
                places.forEach((place) => {
                    if (place.location && place.location.coordinates) {
                        const [lng, lat] = place.location.coordinates;
                        if (lat && lng) allPoints.push([lat, lng]);
                    }
                });
            }

            // If we have data points, center on them (Priority 1)
            if (allPoints.length > 0) {
                debug(`Priority 1: Centering map on ${allPoints.length} data points`);
                if (allPoints.length === 1) {
                    // Single point - center with reasonable zoom
                    this.map.setView(allPoints[0], 15);
                } else {
                    // Multiple points - fit bounds
                    const bounds = L.latLngBounds(allPoints);
                    this.map.fitBounds(bounds, { padding: [20, 20] });
                }
                return; // Successfully centered on data
            }

            // Priority 2: No data found, try browser location
            debug('Priority 2: No data points found, attempting to get browser location...');
            await this.#updateMapLocationAsFallback();

        } catch (error) {
            debug('Error in map centering:', error.message);
            // Priority 3: If everything fails, Singapore defaults will be used from template
        }
    }

    #startLoadSequence() {
        if (this._loadAttempted) {
            debug('Load already attempted, skipping...');
            return;
        }
        this._loadAttempted = true;

        // Wait for map to be properly initialized before loading resources
        // Add a small delay to ensure DOM is fully settled
        setTimeout(() => {
            debug('Starting map initialization sequence...');
            // Use the more robust ensureInteractive method from leafletMapManager
            this.leafletMapManager
                .ensureInteractive({ timeoutMs: 10000 })
                .then(() => {
                    debug('Map is interactive, starting resource loading...');
                    this.load.perform();
                })
                .catch((error) => {
                    debug('Map initialization failed, proceeding anyway:', error.message);
                    // Still attempt to load resources even if map initialization fails
                    this.load.perform();
                });
        }, 100);

        // Fallback timeout to prevent infinite loading (15 seconds)
        this._fallbackTimeout = setTimeout(() => {
            if (!this.ready) {
                debug('Map loading fallback timeout triggered - marking as failed');
                this.failed = true;
                this.ready = false;

                // Auto-retry once after 3 seconds if this is the first failure
                if (this.retryCount === 0) {
                    debug('Scheduling automatic retry in 3 seconds...');
                    setTimeout(() => {
                        if (this.failed && !this.ready) {
                            this.retryMapLoad();
                        }
                    }, 3000);
                }
            }
        }, 15000);
    }

    #clearFallbackTimeout() {
        if (this._fallbackTimeout) {
            clearTimeout(this._fallbackTimeout);
            this._fallbackTimeout = null;
        }
    }

    willDestroy() {
        super.willDestroy();
        this.#clearFallbackTimeout();
        // Cleanup any running promises to prevent memory leaks
        if (this._locationPromise) {
            this._locationPromise = null;
        }
    }

    #setMap(map) {
        set(map, 'livemap', this);
        this.map = map;
        this.leafletMapManager.setMap(map);
        this.universe.trigger('fleet-ops.live-map.loaded', map);
        this.universe.set('component:fleet-ops:live-map', this);
    }

    #setResourceLayer(model, layer, options = {}) {
        const { hidden = false } = options;
        const type = getModelName(model);

        set(model, 'leafletLayer', layer);
        set(layer, 'record_id', model.id);
        set(layer, 'record_type', type);

        this.leafletLayerVisibilityManager.registerLayer(pluralize(type), layer, { id: model.id, hidden });
    }

    #createMapContextMenu(map) {
        const items = [
            {
                text: this.intl.t('live-map.show-coordinates'),
                callback: this.leafletMapManager.showCoordinates,
                index: 0,
            },
            {
                text: this.intl.t('live-map.center-map'),
                callback: this.leafletMapManager.centerMap,
                index: 1,
            },
            {
                text: this.intl.t('live-map.zoom-in'),
                callback: this.leafletMapManager.zoomIn,
                index: 2,
            },
            {
                text: this.intl.t('live-map.zoom-out'),
                callback: this.leafletMapManager.zoomOut,
                index: 3,
            },
            {
                text: this.intl.t('live-map.toggle-draw-controls'),
                callback: this.leafletMapManager.toggleDrawControl,
                index: 4,
            },
            { separator: true },
            {
                text: this.intl.t('live-map.create-new-service'),
                callback: () => this.geofence.createServiceArea(),
                index: 5,
            },
            this.serviceAreaActions.serviceAreas.length ? { separator: true } : null,
            ...this.serviceAreaActions.serviceAreas.map((serviceArea, i) => {
                return {
                    text: this.intl.t('live-map.focus-service', { serviceName: serviceArea.name }),
                    callback: () => this.geofence.focusServiceArea(serviceArea),
                    index: 6 + i,
                };
            }),
        ].filter(Boolean);

        const registry = this.leafletContextmenuManager.createContextMenu('map', map, items);
        this.universe.createRegistryEvent('fleet-ops:contextmenu:map', 'created', registry, this.leafletContextmenuManager);

        return registry;
    }

    #createZoneContextMenu(zone, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.edit-zone', { zoneName: zone.name }),
                callback: () => this.zoneActions.modal.edit(zone),
            },
            {
                text: this.intl.t('live-map.edit-boundaries', { resource: zone.name }),
                callback: () => this.geofence.editZone(zone),
            },
            {
                text: this.intl.t('live-map.delete-zone', { zoneName: zone.name }),
                callback: () => this.zoneActions.delete(zone),
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`zone:${zone.public_id}`, layer, items, { zone });
        this.universe.createRegistryEvent('fleet-ops:contextmenu:zone', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createServiceAreaContextMenu(serviceArea, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.blur-service', { serviceName: serviceArea.name }),
                callback: () => this.geofence.blurServiceArea(serviceArea),
            },
            {
                text: this.intl.t('live-map.create-zone', { serviceName: serviceArea.name }),
                callback: () => this.geofence.createZone(serviceArea),
            },
            {
                text: this.intl.t('live-map.edit-service', { serviceName: serviceArea.name }),
                callback: () => this.serviceAreaActions.modal.edit(serviceArea),
            },
            {
                text: this.intl.t('live-map.edit-boundaries', { resource: serviceArea.name }),
                callback: () => this.geofence.editServiceArea(serviceArea),
            },
            {
                text: this.intl.t('live-map.delete-service', { serviceName: serviceArea.name }),
                callback: () => this.serviceAreaActions.delete(serviceArea),
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`service-area:${serviceArea.public_id}`, layer, items, { serviceArea });
        this.universe.createRegistryEvent('fleet-ops:contextmenu:service-area', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createDriverContextMenu(driver, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.view-driver', { driverName: driver.name }),
                callback: () => this.driverActions.panel.view(driver),
            },
            {
                text: this.intl.t('live-map.edit-driver', { driverName: driver.name }),
                callback: () => this.driverActions.panel.edit(driver, { useDefaultSaveTask: true }),
            },
            {
                text: this.intl.t('live-map.delete-driver', { driverName: driver.name }),
                callback: () => this.driverActions.delete(driver),
            },
            {
                text: this.intl.t('live-map.view-vehicle-for', { driverName: driver.name }),
                callback: () => this.vehicleActions.panel.view(driver.vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('fleet-ops:contextmenu:driver');
        if (isArray(registeredContextMenuItems)) {
            items = [
                ...items,
                ...registeredContextMenuItems.map((menuItem) => {
                    return {
                        text: menuItem.title,
                        callback: () => {
                            const callbackContext = {
                                driver,
                                layer,
                                contextmenuService: this.leafletContextmenuManager,
                                menuItem,
                            };
                            return menuItem.onClick(callbackContext);
                        },
                    };
                }),
            ];
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`driver:${driver.public_id}`, layer, items, { driver });
        this.universe.createRegistryEvent('fleet-ops:contextmenu:driver', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createVehicleContextMenu(vehicle, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.view-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.panel.view(vehicle),
            },
            {
                text: this.intl.t('live-map.edit-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.panel.edit(vehicle, { useDefaultSaveTask: true }),
            },
            {
                text: this.intl.t('live-map.delete-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.delete(vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('fleet-ops:contextmenu:vehicle');
        if (isArray(registeredContextMenuItems)) {
            items = [
                ...items,
                ...registeredContextMenuItems.map((menuItem) => {
                    return {
                        text: menuItem.title,
                        callback: () => {
                            const callbackContext = {
                                vehicle,
                                layer,
                                contextmenuService: this.leafletContextmenuManager,
                                menuItem,
                            };
                            return menuItem.onClick(callbackContext);
                        },
                    };
                }),
            ];
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`vehicle:${vehicle.public_id}`, layer, items, { vehicle });
        this.universe.createRegistryEvent('fleet-ops:contextmenu:vehicle', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #changeTileSource(source) {
        switch (source) {
            case 'dark':
                this.theme = 'dark';
                this.tileUrl = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
                break;
            case 'custom':
                this.theme = 'custom';
                this.tileUrl = source.startsWith('https://') ? source : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
                break;
            case 'light':
            default:
                this.theme = 'light';
                this.tileUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
                break;
        }
    }
}
