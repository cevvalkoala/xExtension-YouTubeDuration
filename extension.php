<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/YouTubeDurationService.php';

/**
 * FreshRSS YouTube Duration extension.
 *
 * Adds a YouTube duration marker to imported YouTube entries. The API key and
 * formatting choices are stored in FreshRSS's system extension configuration.
 */
final class YouTubeDurationExtension extends Minz_Extension
{
    public string $apiKey = '';
    public string $durationPosition = 'before';
    public string $shortsMode = 'shorts';

    #[\Override]
    public function init(): void
    {
        parent::init();

        $this->registerHook(
            Minz_HookType::EntryBeforeInsert,
            [$this, 'addDurationToTitle']
        );
    }

    #[\Override]
    public function handleConfigureAction(): void
    {
        parent::handleConfigureAction();

        if (Minz_Request::isPost()) {
            $apiKey = trim(Minz_Request::paramString('api_key'));
            $durationPosition = Minz_Request::paramString('duration_position') === 'after' ? 'after' : 'before';
            $shortsMode = Minz_Request::paramString('shorts_mode');
            if (!in_array($shortsMode, ['shorts', 'duration', 'none'], true)) {
                $shortsMode = 'shorts';
            }

            $this->setSystemConfigurationValue('api_key', $apiKey);
            $this->setSystemConfigurationValue('duration_position', $durationPosition);
            $this->setSystemConfigurationValue('shorts_mode', $shortsMode);
        }

        $this->loadConfiguration();
    }

    public function addDurationToTitle(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $this->loadConfiguration();

        if ($this->apiKey === '') {
            error_log('YouTube Duration extension: no YouTube Data API key has been configured.');
            return $entry;
        }

        $service = new YouTubeDurationService(
            $this->apiKey,
            $this->durationPosition,
            $this->shortsMode,
        );

        return $service->processEntry($entry);
    }

    private function loadConfiguration(): void
    {
        $this->apiKey = $this->getSystemConfigurationString('api_key') ?? '';
        $this->durationPosition = $this->getSystemConfigurationString('duration_position') ?: 'before';
        $this->shortsMode = $this->getSystemConfigurationString('shorts_mode') ?: 'shorts';

        if (!in_array($this->durationPosition, ['before', 'after'], true)) {
            $this->durationPosition = 'before';
        }
        if (!in_array($this->shortsMode, ['shorts', 'duration', 'none'], true)) {
            $this->shortsMode = 'shorts';
        }
    }
}
