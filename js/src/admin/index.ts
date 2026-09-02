/**
 * Admin entry.
 *
 * 🚨 Declared through the `extend` export, NOT `app.initializers.add(...)` +
 * `app.extensionData`. In Flarum 2 the initializer runs before
 * `app.extensionData` exists, so the older pattern throws "Cannot read
 * properties of undefined (reading 'for')" and the whole extension is reported
 * as failing to initialise — settings, permissions and all.
 */
export { default as extend } from './extend';
