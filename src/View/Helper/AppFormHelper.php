<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Utility\Inflector;
use Cake\View\Helper\FormHelper;

/**
 * CakePHP 2 compatible form helper methods (inputExp, inputRadio, searchField, searchDate, inputDate).
 * Extends CakePHP 5 FormHelper.
 */
class AppFormHelper extends FormHelper
{
    /**
     * Override create() to auto-detect entity from controller name when null is passed.
     * CakePHP 2's FormHelper::create(null) inferred the model from the controller name
     * and used `data[ModelName]` prefix for fields. CakePHP 5's NullContext does not.
     * This override restores that behavior by finding the entity from view variables.
     *
     * @param mixed $context The form context (entity, table name, or null).
     * @param array $options Form options.
     * @return string
     */
    public function create(mixed $context = null, array $options = []): string
    {
        if ($context === null) {
            $controller = $this->_View->getRequest()->getParam('controller');
            if ($controller) {
                $tableAlias = Inflector::camelize($controller);

                // CakePHP 2's Form->create(null) inferred the model from controller name
                // and bound the entity automatically. CakePHP 5 NullContext does not.
                // Try multiple strategies to find the entity:
                // 1. View variable (singularized controller name: 'content')
                // 2. View variable (CamelCase: 'Content')
                // 3. TableRegistry lookup + newEmptyEntity

                $entityName = Inflector::singularize($controller);
                // View vars are lowercase (set by $this->set('content', $entity))
                // but Inflector::singularize may return CamelCase in CakePHP 5
                $underscoreName = Inflector::underscore($entityName);

                $allVars = $this->_View->getVars();
                $entity = $this->_View->get($underscoreName)
                    ?? $this->_View->get($entityName)
                    ?? $this->_View->get($tableAlias);
                file_put_contents('/var/www/html/tmp/debug_form.log', date('c') . " ctrl=$controller underscore=$underscoreName vars=[" . implode(',', $allVars) . "] found=" . (is_object($entity) ? 'OBJ:' . get_class($entity) : 'NULL') . "\n", FILE_APPEND);

                if (is_object($entity)) {
                    $context = $entity;
                } else {
                    try {
                        $table = \Cake\ORM\TableRegistry::getTableLocator()->get($tableAlias);
                        $context = $table->newEmptyEntity();
                        file_put_contents('/var/www/html/tmp/debug_form.log', date('c') . " newEmptyEntity created for $tableAlias\n", FILE_APPEND);
                    } catch (\Exception $e) {
                        file_put_contents('/var/www/html/tmp/debug_form.log', date('c') . " TableRegistry failed: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
            }
        }

        return parent::create($context, $options);
    }

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

        // CakePHP 5 RadioWidget uses 'radioWrapper' template, not 'separator'.
        // Convert the CakePHP 2 separator option into a template override.
        // Note: CakePHP 2 placed separators BETWEEN options (not after the last).
        // RadioWrapper applies to EVERY option, so we must trim the trailing separator.
        $separator = $options['separator'] ?? "\n";
        unset($options['separator'], $options['div']);
        $options['templates'] = array_merge(
            $options['templates'] ?? [],
            ['radioWrapper' => '{{input}}{{label}}' . $separator]
        );

        $result = $this->control($fieldName, $options);

        // Remove trailing separator (CakePHP 2 placed separators between items, not after last).
        // The separator appears inside a wrapping <div>, so remove the last <br> before </div>.
        if ($separator !== '' && $separator !== "\n") {
            $result = preg_replace('/' . preg_quote($separator, '/') . '(\s*)<\/div>/', '$1</div>', $result);
        }

        return $result;
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

        // Set label with " : " suffix — always append to match CakePHP 2 behavior
        if (!array_key_exists('label', $additional_options)) {
            $label = $this->_getElementId($fieldName);
            // Convert dot notation to human-readable: "foo.bar" → "Bar"
            $labelText = array_slice(explode('.', $label), -1)[0];
            $additional_options['label'] = $labelText . ' : ';
        } else {
            $additional_options['label'] = rtrim($additional_options['label'] . ' : ');
        }

        $options = array_merge($defaults, $additional_options);

        // When 'options' is provided, let CakePHP 5 auto-detect as 'select'.
        // Otherwise default to 'text'.
        if (!isset($options['options'])) {
            $options['type'] = 'text';
        }

        // Cake2 input() → Cake5 control()
        return $this->control($fieldName, $options);
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

        // Set label with " : " suffix — always append to match CakePHP 2 behavior
        if (!array_key_exists('label', $additional_options)) {
            $label = $this->_getElementId($fieldName);
            $labelText = array_slice(explode('.', $label), -1)[0];
            $additional_options['label'] = $labelText . ' : ';
        } else {
            $additional_options['label'] = rtrim($additional_options['label'] . ' : ');
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
