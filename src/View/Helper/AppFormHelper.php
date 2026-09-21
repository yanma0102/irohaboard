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

        return parent::control($fieldName, $options);
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

        if ($exp !== '') {
            $afterHtml = '<div class="col-sm-4">' . $exp . '</div>';
            if (isset($options['after']) && $options['after'] !== '') {
                $options['after'] = $options['after'] . $afterHtml;
            } else {
                $options['after'] = $afterHtml;
            }
        }

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
