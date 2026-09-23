<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\Core\Configure;
use Cake\View\Helper\FormHelper;

/**
 * CakePHP 2 compatible form helper methods (inputExp, inputRadio, searchField, searchDate, inputDate).
 * Extends CakePHP 5 FormHelper.
 */
class AppFormHelper extends FormHelper
{
    /**
     * Override control() to merge form_input_defaults from config.
     * This replaces CakePHP 2's FormHelper::create(inputDefaults) behavior.
     *
     * @param string $fieldName Field name (dot notation).
     * @param array  $options  Options for the control.
     * @return string
     */
    public function control(string $fieldName, array $options = []): string
    {
        $defaults = Configure::read('form_input_defaults');
        if ($defaults && !isset($options['_inputDefaultsApplied'])) {
            // Merge defaults: options take precedence over defaults
            foreach ($defaults as $key => $defaultVal) {
                if (!array_key_exists($key, $options)) {
                    $options[$key] = $defaultVal;
                } elseif (is_array($defaultVal) && is_array($options[$key])) {
                    $options[$key] = array_merge($defaultVal, $options[$key]);
                }
            }
        }

        // CakePHP 2 legacy keys that CakePHP 5 does not recognize.
        // These leak as HTML attributes on <input> via Widget::formatAttributes().
        unset($options['wrapInput'], $options['div']);

        // CakePHP 2 'before'/'after' rendered content before/after the control.
        // CakePHP 5 does not consume these, so we extract and render them manually.
        $beforeHtml = $options['before'] ?? '';
        $afterHtml = $options['after'] ?? '';
        unset($options['before'], $options['after']);

        // Auto-detect 'id' field as hidden when no explicit type is set.
        // NullContext (Form->create(null)) always returns false for isPrimaryKey(),
        // so CakePHP 5's _inputType() incorrectly returns 'text' for the 'id' field.
        $shortName = array_slice(explode('.', $fieldName), -1)[0];
        if ($shortName === 'id' && empty($options['type'])) {
            $options['type'] = 'hidden';
        }

        $result = parent::control($fieldName, $options);

        return $beforeHtml . $result . $afterHtml;
    }

    /**
     * Form input with an explanation div in the `after` area.
     * Matches legacy Cake2 AppHelper::inputExp().
     *
     * @param string $fieldName Field name (dot notation).
     * @param array  $options  Options merged with 'after' explanation div.
     * @param string $exp      Explanation text appended after the control.
     * @return string
     */
    public function inputExp(string $fieldName, array $options = [], string $exp = ''): string
    {
        if ($exp !== '') {
            $afterHtml = '<div class="col-sm-4">' . $exp . '</div>';
            if (isset($options['after']) && $options['after'] !== '') {
                $options['after'] = $options['after'] . $afterHtml;
            } else {
                $options['after'] = $afterHtml;
            }
        }

        // Cake2 input() → Cake5 control()
        return $this->control($fieldName, $options);
    }

    /**
     * Radio input with explanation div.
     * Matches legacy Cake2 AppHelper::inputRadio().
     *
     * @param string $fieldName Field name.
     * @param array  $options  Options for the control.
     * @param string $exp      Explanation text.
     * @return string
     */
    public function inputRadio(string $fieldName, array $options = [], string $exp = ''): string
    {
        $defaults = [
            'type'      => 'radio',
            'separator' => "\n",
            'legend'    => false,
            'class'     => 'form-control',
            'before'    => '',
            'div'       => false,
        ];

        // Merge but don't overwrite explicitly-set options
        foreach ($defaults as $key => $defaultVal) {
            if (!array_key_exists($key, $options)) {
                $options[$key] = $defaultVal;
            }
        }

        // HTML labels (e.g. content_kind_comment contains <span> tags).
        // CakePHP 5 RadioWidget defaults to escape => true which escapes HTML.
        if (!isset($options['escape'])) {
            $options['escape'] = false;
        }

        if ($exp !== '') {
            $afterHtml = '<div class="col-sm-4">' . $exp . '</div>';
            if (isset($options['after']) && $options['after'] !== '') {
                $options['after'] = $options['after'] . $afterHtml;
            } else {
                $options['after'] = $afterHtml;
            }
        }

        // Remove CakePHP 2 keys that CakePHP 5 does not recognize.
        // 'separator' is not used by RadioWidget; 'div' leaks as HTML attribute.
        // 'before'/'after' are handled by control() as raw HTML.
        unset($options['separator'], $options['div']);

        return $this->control($fieldName, $options);
    }

    /**
     * Text search field with label suffix " : ".
     * Matches legacy Cake2 AppHelper::searchField().
     *
     * @param string $fieldName          Field name.
     * @param array  $additional_options Additional options.
     * @return string
     */
    public function searchField(string $fieldName, array $additional_options = []): string
    {
        $defaults = [
            'class'    => 'form-control',
            'required' => false,
        ];

        // Set label with " : " suffix if not explicitly set
        if (!array_key_exists('label', $additional_options)) {
            $label = $this->_getElementId($fieldName);
            // Convert dot notation to human-readable: "foo.bar" → "Bar"
            $labelText = array_slice(explode('.', $label), -1)[0];
            $additional_options['label'] = $labelText . ' : ';
        }

        $options = array_merge($defaults, $additional_options);

        // Cake2 input() → Cake5 control()
        return $this->control($fieldName, ['type' => 'text'] + $options);
    }

    /**
     * Date search field with label suffix " : ".
     * Matches legacy Cake2 AppHelper::searchDate().
     *
     * @param string $fieldName          Field name.
     * @param array  $additional_options Additional options.
     * @return string
     */
    public function searchDate(string $fieldName, array $additional_options = []): string
    {
        $currentYear = (int)date('Y');
        $defaults = [
            'class'     => 'form-control',
            'required'  => false,
            'minYear'   => $currentYear,
            'maxYear'   => $currentYear,
            'separator' => ' / ',
            'style'     => 'width:initial; display: inline;',
        ];

        // Set label with " : " suffix if not explicitly set
        if (!array_key_exists('label', $additional_options)) {
            $label = $this->_getElementId($fieldName);
            $labelText = array_slice(explode('.', $label), -1)[0];
            $additional_options['label'] = $labelText . ' : ';
        }

        $options = array_merge($defaults, $additional_options);

        return $this->control($fieldName, ['type' => 'date'] + $options);
    }

    /**
     * Date input with formatted defaults.
     * Matches legacy Cake2 AppHelper::inputDate().
     *
     * @param string $fieldName          Field name.
     * @param array  $additional_options Additional options.
     * @return string
     */
    public function inputDate(string $fieldName, array $additional_options = []): string
    {
        $currentYear = (int)date('Y');
        $defaults = [
            'class'      => 'form-control',
            'required'   => false,
            'minYear'    => $currentYear - 5,
            'maxYear'    => $currentYear + 5,
            'separator'  => ' / ',
            'style'      => 'width:initial; display: inline;',
        ];

        // Set label with " : " suffix unless label is '～'
        if (!array_key_exists('label', $additional_options)) {
            $label = $this->_getElementId($fieldName);
            $labelText = array_slice(explode('.', $label), -1)[0];
            $additional_options['label'] = $labelText . ' : ';
        } elseif ($additional_options['label'] === '～') {
            // Keep the label as-is
            unset($additional_options['label']);
            $additional_options['label'] = '～';
        }

        $options = array_merge($defaults, $additional_options);

        return $this->control($fieldName, ['type' => 'date'] + $options);
    }

    /**
     * Get a human-readable element ID from field name.
     *
     * @param string $fieldName
     * @return string
     */
    protected function _getElementId(string $fieldName): string
    {
        return $fieldName;
    }
}
