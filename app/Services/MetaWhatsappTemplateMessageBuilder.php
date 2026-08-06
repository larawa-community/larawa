<?php

namespace App\Services;

use App\Data\MetaWhatsappTemplate;
use Illuminate\Validation\ValidationException;

class MetaWhatsappTemplateMessageBuilder
{
    /** @return array<int, array<string, mixed>> */
    public function parameterSchema(MetaWhatsappTemplate $template): array
    {
        $fields = [];

        foreach ($template->components ?: [] as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'BODY') {
                preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*|\d+)\s*\}\}/', (string) ($component['text'] ?? ''), $matches);
                foreach (array_values(array_unique($matches[1] ?? [])) as $position => $variable) {
                    $fields[] = [
                        'key' => 'body_'.$variable,
                        'label' => ctype_digit($variable) ? 'Body value '.($position + 1) : str_replace('_', ' ', ucfirst($variable)),
                        'component' => 'body',
                        'input' => 'text',
                        'required' => true,
                        'variable' => $variable,
                        'parameter_name' => ctype_digit($variable) ? null : $variable,
                    ];
                }
            }

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
                if ($format !== 'TEXT' || str_contains((string) ($component['text'] ?? ''), '{{')) {
                    $fields[] = [
                        'key' => 'header',
                        'label' => $format === 'TEXT' ? 'Header value' : ucfirst(strtolower($format)).' upload',
                        'component' => 'header',
                        'format' => strtolower($format),
                        'input' => $format === 'TEXT' ? 'text' : 'file',
                        'required' => true,
                    ];
                }
            }

            if ($type === 'BUTTONS') {
                foreach (($component['buttons'] ?? []) as $index => $button) {
                    if (strtoupper((string) ($button['type'] ?? '')) === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{')) {
                        $fields[] = [
                            'key' => 'button_'.$index,
                            'label' => ($button['text'] ?? 'Button '.($index + 1)).' URL value',
                            'component' => 'button',
                            'input' => 'text',
                            'required' => true,
                            'index' => $index,
                        ];
                    }
                }
            }
        }

        return $fields;
    }

    /** @return array<int, array<string, mixed>> */
    public function components(MetaWhatsappTemplate $template, array $parameters): array
    {
        $components = [];
        $body = [];

        foreach ($this->parameterSchema($template) as $field) {
            if ($field['component'] === 'header' && ($field['input'] ?? null) === 'file') {
                continue;
            }
            $value = trim((string) ($parameters[$field['key']] ?? ''));
            if ($value === '') {
                throw ValidationException::withMessages([
                    'parameters.'.$field['key'] => $field['label'].' is required for this template.',
                ]);
            }

            if ($field['component'] === 'body') {
                $parameter = ['type' => 'text', 'text' => $value];
                if ($field['parameter_name']) {
                    $parameter['parameter_name'] = $field['parameter_name'];
                }
                $body[] = $parameter;
            } elseif ($field['component'] === 'header') {
                $format = $field['format'];
                $parameter = $format === 'text'
                    ? ['type' => 'text', 'text' => $value]
                    : ['type' => $format, $format => ['link' => $value]];
                $components[] = ['type' => 'header', 'parameters' => [$parameter]];
            } else {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => (string) $field['index'],
                    'parameters' => [['type' => 'text', 'text' => $value]],
                ];
            }
        }

        if ($body !== []) {
            array_unshift($components, ['type' => 'body', 'parameters' => $body]);
        }

        return $components;
    }
}
