<?php
/**
 * SEOMate plugin for Craft CMS 3.x
 *
 * @link      https://www.vaersaagod.no/
 * @copyright Copyright (c) 2019 Værsågod
 */

namespace vaersaagod\seomate\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;

use vaersaagod\seomate\models\Settings;
use vaersaagod\seomate\SEOMate;
use vaersaagod\seomate\helpers\CacheHelper;
use vaersaagod\seomate\helpers\SEOMateHelper;


/**
 * @author    Værsågod
 * @package   SEOMate
 * @since     1.0.0
 */
class MetaService extends Component
{

    /**
     * Gets all meta data based on context
     *
     * @param array $context
     * @return array
     */
    public function getContextMeta($context): array
    {
        $craft = Craft::$app;
        $settings = SEOMate::$plugin->getSettings();

        $overrideObject = $context['seomate'] ?? null;

        if ($overrideObject && isset($overrideObject['config'])) {
            SEOMateHelper::updateSettings($settings, $overrideObject['config']);
        }

        if ($overrideObject && isset($overrideObject['element'])) {
            $element = $overrideObject['element'];
        } else {
            $element = $craft->urlManager->getMatchedElement();
        }

        // Check if we have a cache
        if ($element && $settings->cacheEnabled && CacheHelper::hasMetaCacheForElement($element)) {
            return CacheHelper::getMetaCacheForElement($element);
        }

        $meta = [];

        // Get element meta data
        if ($element) {
            $meta = $this->getElementMeta($element, $overrideObject);
        }

        // Additional meta data
        if ($settings->additionalMeta !== null && \count($settings->additionalMeta) > 0) {
            $meta = $this->processAdditionalMeta($meta, $context, $settings);
        }
        
        // Overwrite with pre-generated values from template
        if ($overrideObject && isset($overrideObject['meta'])) {
            $this->overrideMeta($meta, $overrideObject['meta']);
        }

        // Add default meta if available
        if ($settings->defaultMeta !== null && \count($settings->defaultMeta) > 0) {
            $meta = $this->processDefaultMeta($meta, $context, $settings);
        }

        // Autofill missing attributes
        $meta = $this->autofillMeta($meta, $settings);

        // Parse assets if applicable
        if (!$settings->returnImageAsset) {
            $meta = $this->transformMetaAssets($meta, $settings);
        }

        // Apply restrictions
        if ($settings->applyRestrictions) {
            $meta = $this->applyMetaRestrictions($meta, $settings);
        }
        
        // Filter and encode
        $meta = $this->applyMetaFilters($meta);

        // Add sitename if desirable
        if ($settings->includeSitenameInTitle) {
            $meta = $this->addSitename($meta, $context, $settings);
        }
        
        // Cache it
        if ($element && $settings->cacheEnabled) {
            CacheHelper::setMetaCacheForElement($element, $meta);
        }

        return $meta;
    }

    /**
     * Gets all element meta data
     *
     * @param Element $element
     * @param null|array $overrides
     * @return array
     */
    public function getElementMeta($element, $overrides = null): array
    {
        $settings = SEOMate::$plugin->getSettings();

        if ($overrides && isset($overrides['config'])) {
            SEOMateHelper::updateSettings($settings, $overrides['config']);
        }

        $profile = null;

        if ($overrides && isset($overrides['profile'])) {
            $profile = $overrides['profile'];
        } else {
            $profile = SEOMateHelper::getElementProfile($element, $settings);
        }

        if ($profile === null) {
            $profile = $settings->defaultProfile ?? null;
        }

        $meta = [];

        if ($profile && isset($settings->fieldProfiles[$profile])) {
            $fieldProfile = $settings->fieldProfiles[$profile];

            $meta = $this->generateElementMetaByProfile($element, $fieldProfile);
        }

        return $meta;
    }

    /**
     * Gets element meta data based on profile
     *
     * @param Element $element
     * @param array $profile
     * @return array
     */
    public function generateElementMetaByProfile($element, $profile): array
    {
        $r = [];

        foreach ($profile as $key => $value) {
            $keyType = SEOMateHelper::getMetaTypeByKey($key);
            $r[$key] = $this->getElementPropertyDataByFields($element, $keyType, $value);
        }

        return $r;
    }

    /**
     * Gets the value for a meta data property in *element*, from a list of fields and type.
     *
     * @param Element $element
     * @param string $type
     * @param array $fields
     * @return mixed
     */
    public function getElementPropertyDataByFields($element, $type, $fields)
    {
        foreach ($fields as $fieldHandle) {
            $fieldValue = SEOMateHelper::getPropertyDataByScopeAndHandle($element, $fieldHandle, $type);
            
            if ($fieldValue !== null) {
                return $fieldValue;
            }
        }

        return '';
    }

    /**
     * Gets the value for a meta data property in *context*, from a list of fields and type.
     *
     * @param $context
     * @param string $type
     * @param array $fields
     * @return mixed
     */
    public function getContextPropertyDataByFields($context, $type, $fields)
    {
        foreach ($fields as $fieldName) {
            // Get the deepest scope possible, and the remaining field handle.
            list($primaryScope, $fieldHandle) = SEOMateHelper::reduceScopeAndHandle($context, $fieldName);
            
            $fieldValue = SEOMateHelper::getPropertyDataByScopeAndHandle($primaryScope, $fieldHandle, $type);
            
            if ($fieldValue !== null) {
                return $fieldValue;
            }
        }

        return '';
    }

    /**
     * Transforms meta data assets.
     *
     * @param array $meta
     * @param null|Settings $settings
     * @return array
     */
    public function transformMetaAssets($meta, $settings = null): array
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        $imageTransformMap = $settings->imageTransformMap;

        foreach ($imageTransformMap as $key => $value) {
            if (isset($meta[$key]) && $meta[$key] !== null && $meta[$key] !== '') {

                $transform = $imageTransformMap[$key];
                $asset = $meta[$key] ?? null;

                if ($asset) {
                    $meta[$key] = $this->getTransformedUrl($asset, $transform, $settings);

                    $alt = null;

                    if ($settings->altTextFieldHandle && $asset[$settings->altTextFieldHandle] && ((string)$asset[$settings->altTextFieldHandle] !== '')) {
                        $alt = $asset->getAttributes()[$settings->altTextFieldHandle];
                    }

                    if ($key === 'og:image') {
                        if ($alt) {
                            $meta[$key . ':alt'] = $alt;
                        }
                        if (isset($transform['format'])) {
                            $meta[$key . ':type'] = 'image/' . ($transform['format'] === 'jpg' ? 'jpeg' : $transform['format']);
                        }
                        // todo: Ideally, we should get these from the final transform
                        if (isset($transform['width'])) {
                            $meta[$key . ':width'] = $transform['width'];
                        }
                        if (isset($transform['height'])) {
                            $meta[$key . ':height'] = $transform['height'];
                        }
                    }
                    if ($key === 'twitter:image') {
                        if ($alt) {
                            $meta[$key . ':alt'] = $alt;
                        }
                    }

                }
            }
        }

        return $meta;
    }

    /**
     * Transforms asset and returns URL.
     *
     * @param Asset|string $asset
     * @param array $transform
     * @param null|Settings $settings
     * @return string
     */
    public function getTransformedUrl($asset, $transform, $settings = null): string
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        $plugins = Craft::$app->getPlugins();
        $imagerPlugin = $plugins->getPlugin('imager-x') ?? $plugins->getPlugin('imager');
        
        $transformedUrl = '';

        if ($settings->useImagerIfInstalled && $imagerPlugin && ($imagerPlugin instanceof \aelvan\imager\Imager || $imagerPlugin instanceof \spacecatninja\imagerx\ImagerX)) {
            if (!\is_string($asset) && !isset($transform['position']) && isset($asset['focalPoint'])) {
                $transform['position'] = $asset['focalPoint'];
            }

            try {
                $transformedAsset = $imagerPlugin->imager->transformImage($asset, $transform, [], []);

                if ($transformedAsset) {
                    $transformedUrl = $transformedAsset->getUrl();
                }
            } catch (\Throwable $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        } else {
            $generateTransformsBeforePageLoad = Craft::$app->config->general->generateTransformsBeforePageLoad;
            Craft::$app->config->general->generateTransformsBeforePageLoad = true;
            
            $transformedUrl = $asset->getUrl($transform);
            
            Craft::$app->config->general->generateTransformsBeforePageLoad = $generateTransformsBeforePageLoad;
        }

        if (!$transformedUrl) {
            return '';
        }

        return SEOMateHelper::ensureAbsoluteUrl($transformedUrl);
    }

    /**
     * Applies override meta data
     *
     * @param array $meta
     * @param array $overrideMeta
     */
    public function overrideMeta(&$meta, $overrideMeta)
    {
        foreach ($overrideMeta as $key => $value) {
            $meta[$key] = $value;
        }
    }

    /**
     * Autofills missing meta data based on autofillMap config setting
     *
     * @param array $meta
     * @param null|Settings $settings
     * @return array
     */
    public function autofillMeta($meta, $settings = null): array
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        $autofillMap = SEOMateHelper::expandMap($settings->autofillMap);

        foreach ($autofillMap as $key => $value) {
            if ((!isset($meta[$key]) || $meta[$key] === null) && isset($meta[$value])) {
                $meta[$key] = $meta[$value];
            }
        }

        return $meta;
    }

    /**
     * Applies restrictions to meta data.
     *
     * Currently, only maxLength is enforced.
     *
     * @param array $meta
     * @param null|Settings $settings
     * @return mixed
     */
    public function applyMetaRestrictions($meta, $settings = null)
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        $restrictionsMap = SEOMateHelper::expandMap($settings->metaPropertyTypes);

        foreach ($meta as $key => $value) {
            if (isset($restrictionsMap[$key])) {
                $restrictions = $restrictionsMap[$key];

                if ($restrictions['type'] === 'text' && isset($restrictions['maxLength'])) {
                    if (\strlen($value) > $restrictions['maxLength']) {
                        $meta[$key] = mb_substr($value, 0, $restrictions['maxLength'] - strlen($settings->truncateSuffix)) . $settings->truncateSuffix;
                    }
                }
            }
        }

        return $meta;
    }
    
    /**
     * Apply any filters and encoding
     * 
     * @param array $meta
     * @return mixed
     */
    public function applyMetaFilters($meta)
    {
        foreach ($meta as $key => $value) {
            if (is_string($value) && strpos($value, 'http') !== 0 && strpos($value, '//') !== 0) {
                $meta[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
            }
        }
        return $meta;
    }

    /**
     * Adds sitename to meta properties that should have it, as defined
     * by sitenameTitleProperties config setting.
     *
     * @param array $meta
     * @param array $context
     * @param null|Settings $settings
     * @return array
     */
    public function addSitename($meta, $context, $settings = null): array
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        $siteName = '';

        try {
            if (\is_array($settings->siteName)) {
                $siteName = $settings->siteName[Craft::$app->getSites()->getCurrentSite()->handle] ?? '';
            } else if ($settings->siteName && \is_string($settings->siteName)) {
                $siteName = $settings->siteName;
            } else {
                $configSiteName = Craft::$app->getConfig()->getGeneral()->siteName;

                if (\is_array($configSiteName)) {
                    $configSiteName = $configSiteName[Craft::$app->getSites()->getCurrentSite()->handle] ?? reset($configSiteName);
                }

                $siteName = $configSiteName ?? Craft::$app->getSites()->getCurrentSite()->name ?? '';
            }
        } catch (SiteNotFoundException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }

        if ($siteName !== '') {
            try {
                $siteName = Craft::$app->getView()->renderString($siteName, $context);
            } catch (\Throwable $e) {
                // Ignore, and continue with the current sitename value
            }
            
            $preString = $settings->sitenamePosition === 'before' ? $siteName . ' ' . $settings->sitenameSeparator . ' ' : '';
            $postString = $settings->sitenamePosition === 'after' ? ' ' . $settings->sitenameSeparator . ' ' . $siteName : '';

            foreach ($settings->sitenameTitleProperties as $property) {
                $metaValue = $preString . ($meta[$property] ?? '') . $postString;
                $meta[$property] = \trim($metaValue, " {$settings->sitenameSeparator}");
            }
        }

        return $meta;
    }

    /**
     * Process and return default meta data
     *
     * @param array $meta
     * @param array $context
     * @param null|Settings $settings
     * @return array
     */
    public function processDefaultMeta($meta, $context = [], $settings = null): array
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }

        foreach ($settings->defaultMeta as $key => $value) {
            if (!isset($meta[$key]) || $meta[$key] === null || $meta[$key] === '') {
                $keyType = SEOMateHelper::getMetaTypeByKey($key);
                $meta[$key] = $this->getContextPropertyDataByFields($context, $keyType, $value);
            }
        }

        return $meta;
    }

    /**
     * Processes and returns additional meta data
     *
     * @param array $meta
     * @param array $context
     * @param null|Settings $settings
     * @return array
     */
    public function processAdditionalMeta($meta, $context = [], $settings = null): array
    {
        if ($settings === null) {
            $settings = SEOMate::$plugin->getSettings();
        }
        
        foreach ($settings->additionalMeta as $key => $value) {
            if (\is_callable($value)) {
                $r = $value($context);
                $value = $r;
            }

            if (\is_array($value)) {
                foreach ($value as $subValue) {
                    $renderedValue = SEOMateHelper::renderString($subValue, $context);

                    if ($renderedValue && $renderedValue !== '') {
                        $meta[$key][] = $renderedValue;
                    }
                }
            } else {
                $meta[$key] = SEOMateHelper::renderString($value, $context);
            }
        }

        return $meta;
    }
}
