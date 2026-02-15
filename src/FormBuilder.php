<?php

namespace Skywalker\Html;

use BadMethodCallException;
use DateTime;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Traits\Macroable;

class FormBuilder
{
    use Macroable, Componentable {
        Macroable::__call as macroCall;
        Componentable::__call as componentCall;
    }

    /**
     * The HTML builder instance.
     *
     * @var \Skywalker\Html\HtmlBuilder
     */
    protected $html;

    /**
     * The URL generator instance.
     *
     * @var \Illuminate\Contracts\Routing\UrlGenerator
     */
    protected $url;

    /**
     * The View factory instance.
     *
     * @var \Illuminate\Contracts\View\Factory
     */
    protected $view;

    /**
     * The CSRF token used by the form builder.
     *
     * @var string
     */
    protected $csrfToken;

    /**
     * Consider Request variables while auto fill.
     * @var bool
     */
    protected $considerRequest = false;

    /**
     * The session store implementation.
     *
     * @var \Illuminate\Contracts\Session\Session
     */
    protected $session;

    /**
     * The current model instance for the form.
     *
     * @var mixed
     */
    protected $model;

    /**
     * An array of label names we've created.
     *
     * @var array
     */
    protected $labels = [];

    /**
     * The error bag instance.
     *
     * @var \Illuminate\Support\ViewErrorBag
     */
    protected $errors;

    /**
     * Pending validation rules for the next input.
     *
     * @var string|array|null
     */
    protected $pendingRules = null;

    protected $request;

    /**
     * The reserved form open attributes.
     *
     * @var array
     */
    protected $reserved = ['method', 'url', 'route', 'action', 'files'];

    /**
     * The form methods that should be spoofed, in uppercase.
     *
     * @var array
     */
    protected $spoofedMethods = ['DELETE', 'PATCH', 'PUT'];

    /**
     * The types of inputs to not fill values on by default.
     *
     * @var array
     */
    protected $skipValueTypes = ['file', 'password', 'checkbox', 'radio'];

    /**
     * The payload for the form.
     *
     * @var array
     */
    protected $payload = [];


    /**
     * Input Type.
     *
     * @var null
     */
    protected $type = null;

    /**
     * The ID attribute for the current field.
     *
     * @var string|null
     */
    protected $idAttribute;

    /**
     * Get the HTML builder instance.
     *
     * @return \Skywalker\Html\HtmlBuilder
     */
    public function getHtmlBuilder(): HtmlBuilder
    {
        return $this->html;
    }

    /**
     * Create a new form builder instance.
     *
     * @param  \Skywalker\Html\HtmlBuilder               $html
     * @param  \Illuminate\Contracts\Routing\UrlGenerator $url
     * @param  \Illuminate\Contracts\View\Factory         $view
     * @param  string                                     $csrfToken
     * @param  Request                                    $request
     *
     * @return void
     */
    public function __construct(HtmlBuilder $html, UrlGenerator $url, Factory $view, $csrfToken, ?Request $request = null)
    {
        $this->url = $url;
        $this->view = $view;
        $this->html = $html;
        $this->request = $request;
        $this->csrfToken = $csrfToken;
    }

    /**
     * Open up a new HTML form.
     *
     * @param  array $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function open(array $options = []): HtmlString
    {
        $method = Arr::get($options, 'method', 'post');

        // We need to extract the proper method from the attributes. If the method is
        // something other than GET or POST we'll use POST since we will spoof the
        // actual method since forms don't support the reserved methods in HTML.
        $attributes['method'] = $this->getMethod($method);

        $attributes['action'] = $this->getAction($options);

        $attributes['accept-charset'] = 'UTF-8';

        // If the method is PUT, PATCH or DELETE we will need to add a spoofer hidden
        // field that will instruct the Symfony request to pretend the method is a
        // different method than it actually is, for convenience from the forms.
        $append = $this->getAppendage($method);

        if (isset($options['files']) && $options['files']) {
            $options['enctype'] = 'multipart/form-data';
        }

        // Finally we're ready to create the final form HTML field. We will attribute
        // format the array of attributes. We will also add on the appendage which
        // is used to spoof requests for this PUT, PATCH, etc. methods on forms.
        $attributes = array_merge(

            $attributes,
            Arr::except($options, $this->reserved)

        );

        // Finally, we will concatenate all of the attributes into a single string so
        // we can build out the final form open statement. We'll also append on an
        // extra value for the hidden _method field if it's needed for the form.
        $attributes = $this->html->attributes($attributes);

        return $this->toHtmlString('<form' . $attributes . '>' . $append);
    }

    /**
     * Create a new model based form builder.
     *
     * @param  mixed $model
     * @param  array $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function model(mixed $model, array $options = []): HtmlString
    {
        $this->model = $model;

        return $this->open($options);
    }

    /**
     * Set the model instance on the form builder.
     *
     * @param  mixed $model
     *
     * @return void
     */
    public function setModel(mixed $model): void
    {
        $this->model = $model;
    }

    /**
     * Get the current model instance on the form builder.
     *
     * @return mixed $model
     */
    public function getModel(): mixed
    {
        return $this->model;
    }

    /**
     * Close the current form.
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function close(): string
    {
        $this->labels = [];

        $this->model = null;

        $this->idAttribute = null;

        return '</form>';
    }

    /**
     * Generate a hidden field with the current CSRF token.
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function token(): HtmlString
    {
        $token = ! empty($this->csrfToken) ? $this->csrfToken : $this->session->token();

        return $this->hidden('_token', $token);
    }

    /**
     * Create a form label element.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     * @param  bool   $escape_html
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function label(string $name, ?string $value = null, ?array $options = [], bool $escape_html = true): HtmlString
    {
        $this->labels[] = $name;

        $options = $options ?: [];

        // Apply theme classes if active
        $themeClass = $this->html->getThemeClass('label');
        if ($themeClass) {
            $options['class'] = trim(($options['class'] ?? '') . ' ' . $themeClass);
        }

        $options = $this->html->attributes($options);

        $value = $this->formatLabel($name, $value);

        if ($escape_html) {
            $value = $this->html->entities($value);
        }

        return $this->toHtmlString('<label for="' . $name . '"' . $options . '>' . $value . '</label>');
    }

    /**
     * Format the label value.
     *
     * @param  string      $name
     * @param  string|null $value
     *
     * @return string
     */
    protected function formatLabel(string $name, ?string $value): string
    {
        return $value ?: ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Create a form input field.
     *
     * @param  string $type
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function input($type, $name, $value = null, $options = []): HtmlString
    {
        if (! isset($options['name'])) {
            $options['name'] = $name;
        }

        // Merge pending attributes (Alpine, Vue, etc.)
        $options = array_merge($this->consumePendingAttributes(), $options);

        // We will get the appropriate value for the given field. We will look for the
        // value in the session for the value in the old input data then we'll look
        // in the model instance if one is set otherwise we will just use null.
        $id = $this->getIdAttribute($name, $options);

        // Apply theme classes if active
        $themeClass = $this->html->getThemeClass($type);
        if ($themeClass) {
            $options['class'] = trim(($options['class'] ?? '') . ' ' . $themeClass);
        }

        // Apply error classes if validation errors exist
        if ($this->hasError($name)) {
            $errorClass = $this->getErrorClass();
            if ($errorClass) {
                $options['class'] = trim(($options['class'] ?? '') . ' ' . $errorClass);
            }
        }

        if (! in_array($type, $this->skipValueTypes)) {
            $value = $this->getValueAttribute($name, $value);
        }

        if ($value instanceof DateTime) {
            $format = match ($type) {
                'date' => 'Y-m-d',
                'datetime', 'datetime-local' => 'Y-m-d\TH:i',
                'month' => 'Y-m',
                'time' => 'H:i',
                'week' => 'Y-\WW',
                default => 'Y-m-d H:i:s',
            };
            $value = $value->format($format);
        }

        // Once we have the type, value, and ID as well as any other attributes we are
        // ready to create the final input element for the form. We will also pass
        // through any other options which have been specified through the array.
        $merge = compact('type', 'value', 'id');

        $options = array_merge($options, $merge);

        // Apply validation rules if set
        $options = $this->applyPendingRules($options);

        // Accessibility (A11y) Helpers:
        $options = $this->addA11yAttributes($options);

        $icon = $options['icon'] ?? null;
        unset($options['icon']);

        $input = '<input' . $this->html->attributes($options) . '>';

        if ($icon) {
            $input = $this->wrapWithIcon($input, $icon);
        }

        return $this->toHtmlString($input);
    }

    /**
     * Create a text input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function text(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('text', $name, $value, $options);
    }

    /**
     * Create a password input field.
     *
     * @param  string $name
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function password(string $name, array $options = []): HtmlString
    {
        return $this->input('password', $name, '', $options);
    }

    /**
     * Create a range input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function range(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('range', $name, $value, $options);
    }

    /**
     * Create a hidden input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function hidden(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('hidden', $name, $value, $options);
    }

    /**
     * Create a search input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function search(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('search', $name, $value, $options);
    }

    /**
     * Create an e-mail input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function email(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('email', $name, $value, $options);
    }

    /**
     * Create a tel input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function tel(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('tel', $name, $value, $options);
    }

    /**
     * Create a number input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function number(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('number', $name, $value, $options);
    }

    /**
     * Create a date input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function date(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('date', $name, $value, $options);
    }

    /**
     * Create a datetime input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function datetime(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('datetime', $name, $value, $options);
    }

    /**
     * Create a datetime-local input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function datetimeLocal(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('datetime-local', $name, $value, $options);
    }

    /**
     * Create a time input field.
     *
     * @param  string $name
     * @param  string|null $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function time(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('time', $name, $value, $options);
    }

    /**
     * Create a url input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function url(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('url', $name, $value, $options);
    }

    /**
     * Create a week input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function week(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('week', $name, $value, $options);
    }

    /**
     * Create a file input field.
     *
     * @param  string $name
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function file(string $name, array $options = []): HtmlString
    {
        return $this->input('file', $name, null, $options);
    }



    /**
     * Create a textarea input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function textarea(string $name, ?string $value = null, array $options = []): HtmlString
    {
        $this->type = 'textarea';

        if (! isset($options['name'])) {
            $options['name'] = $name;
        }

        // Merge pending attributes (Alpine, Vue, etc.)
        $options = array_merge($this->consumePendingAttributes(), $options);

        // Next we will look for the rows and cols attributes, as each of these are put
        // on the textarea element definition. If they are not present, we will just
        // assume some sane default values for these attributes for the developer.
        $options = $this->setTextAreaSize($options);

        // Apply theme classes if active
        $themeClass = $this->html->getThemeClass('textarea');
        if ($themeClass) {
            $options['class'] = trim(($options['class'] ?? '') . ' ' . $themeClass);
        }

        // Apply error classes if validation errors exist
        if ($this->hasError($name)) {
            $errorClass = $this->getErrorClass();
            if ($errorClass) {
                $options['class'] = trim(($options['class'] ?? '') . ' ' . $errorClass);
            }
        }

        $options['id'] = $this->getIdAttribute($name, $options);

        $value = (string) $this->getValueAttribute($name, $value);

        unset($options['size']);

        // Accessibility (A11y) Helpers:
        $options = $this->addA11yAttributes($options);

        // Next we will convert the attributes into a string form. Also we have removed
        // the size attribute, as it was merely a short-cut for the rows and cols on
        // the element. Then we'll create the final textarea elements HTML for us.
        $options = $this->html->attributes($options);

        return $this->toHtmlString('<textarea' . $options . '>' . e($value, false) . '</textarea>');
    }

    /**
     * Set the text area size on the attributes.
     *
     * @param  array $options
     *
     * @return array
     */
    protected function setTextAreaSize(array $options): array
    {
        if (isset($options['size'])) {
            return $this->setQuickTextAreaSize($options);
        }

        // If the "size" attribute was not specified, we will just look for the regular
        // columns and rows attributes, using sane defaults if these do not exist on
        // the attributes array. We'll then return this entire options array back.
        $cols = Arr::get($options, 'cols', 50);

        $rows = Arr::get($options, 'rows', 10);

        return array_merge($options, compact('cols', 'rows'));
    }

    /**
     * Set the text area size using the quick "size" attribute.
     *
     * @param  array $options
     *
     * @return array
     */
    protected function setQuickTextAreaSize(array $options): array
    {
        $segments = explode('x', $options['size']);

        return array_merge($options, ['cols' => $segments[0], 'rows' => $segments[1]]);
    }

    /**
     * Create a select box field.
     *
     * @param  string $name
     * @param  array  $list
     * @param  string $selected
     * @param  array  $selectAttributes
     * @param  array  $optionsAttributes
     * @param  array  $optgroupsAttributes
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function select(string $name, iterable $list = [], $selected = null, array $selectAttributes = [], array $optionsAttributes = [], array $optgroupsAttributes = []): HtmlString
    {
        $this->type = 'select';

        // Support for PHP 8.1+ Enums
        if (is_string($list) && enum_exists($list)) {
            $list = $this->getEnumList($list);
        }

        // Merge pending attributes (Alpine, Vue, etc.)
        $selectAttributes = array_merge($this->consumePendingAttributes(), $selectAttributes);

        // When we create a select box field, we also need to check for a selected
        // value in the session, model data, and the request data to see if we
        // should set a particular option to be the default selected value.
        $selected = $this->getValueAttribute($name, $selected);

        $selectAttributes['id'] = $this->getIdAttribute($name, $selectAttributes);

        if (! isset($selectAttributes['name'])) {
            $selectAttributes['name'] = $name;
        }

        // Apply theme classes if active
        $themeClass = $this->html->getThemeClass('select');
        if ($themeClass) {
            $selectAttributes['class'] = trim(($selectAttributes['class'] ?? '') . ' ' . $themeClass);
        }

        // Apply error classes if validation errors exist
        if ($this->hasError($name)) {
            $errorClass = $this->getErrorClass();
            if ($errorClass) {
                $selectAttributes['class'] = trim(($selectAttributes['class'] ?? '') . ' ' . $errorClass);
            }
        }

        // Accessibility (A11y) Helpers:
        $selectAttributes = $this->addA11yAttributes($selectAttributes);

        // We will spin through the list of options and build the proper HTML for
        // each of those. Of course, we'll keep track of the selected value so
        // we can set the selected attribute on the correct option element.
        $html = [];

        if (isset($selectAttributes['placeholder'])) {
            $html[] = $this->placeholderOption($selectAttributes['placeholder'], $selected);
            unset($selectAttributes['placeholder']);
        }

        foreach ($list as $value => $display) {
            $optionAttributes = $optionsAttributes[$value] ?? [];
            $optgroupAttributes = $optgroupsAttributes[$value] ?? [];
            $html[] = $this->getSelectOption($display, $value, $selected, $optionAttributes, $optgroupAttributes);
        }

        // Once we have all of this HTML, we can join this list together into a
        // single string and return this select box as an HTML string to the
        // user. We'll also properly escape the select box's name and ID.
        $selectAttributes = $this->html->attributes($selectAttributes);

        $list = implode('', $html);

        return $this->toHtmlString("<select{$selectAttributes}>{$list}</select>");
    }

    /**
     * Create a select range field.
     *
     * @param  string $name
     * @param  string $begin
     * @param  string $end
     * @param  string $selected
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function selectRange(string $name, $begin, $end, $selected = null, array $options = []): HtmlString
    {
        $range = array_combine($range = range($begin, $end), $range);

        return $this->select($name, $range, $selected, $options);
    }

    /**
     * Create a select year field.
     *
     * @param  string $name
     * @param  string $begin
     * @param  string $end
     * @param  string $selected
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function selectYear(string $name, $begin, $end, $selected = null, array $options = []): HtmlString
    {
        return $this->selectRange($name, $begin, $end, $selected, $options);
    }

    /**
     * Create a select month field.
     *
     * @param  string $name
     * @param  string $selected
     * @param  array  $options
     * @param  string $format
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function selectMonth(string $name, $selected = null, array $options = [], string $format = 'F'): HtmlString
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = date($format, mktime(0, 0, 0, $month, 1));
        }

        return $this->select($name, $months, $selected, $options);
    }

    /**
     * Get the select option for the given value.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @param  array  $optionsAttributes
     * @param  array  $optgroupsAttributes
     *
     * @return \Illuminate\Support\HtmlString|string
     */
    public function getSelectOption($display, $value, $selected, array $optionsAttributes = [], array $optgroupsAttributes = []): HtmlString|string
    {
        if (is_iterable($display)) {
            return $this->optionGroup($display, $value, $selected, $optgroupsAttributes, $optionsAttributes);
        }

        return $this->option($display, $value, $selected, $optionsAttributes);
    }

    /**
     * Create an option group form element.
     *
     * @param  array  $list
     * @param  string $label
     * @param  string $selected
     * @param  array  $attributes
     * @param  array  $optionsAttributes
     * @param  integer  $level
     *
     * @return \Illuminate\Support\HtmlString
     */
    protected function optionGroup(iterable $list, string $label, $selected, array $attributes = [], array $optionsAttributes = [], int $level = 0): HtmlString
    {
        $html = [];
        $space = str_repeat("&nbsp;", $level);
        foreach ($list as $value => $display) {
            $optionAttributes = $optionsAttributes[$value] ?? [];
            if (is_iterable($display)) {
                $html[] = $this->optionGroup($display, $value, $selected, $attributes, $optionAttributes, $level + 5);
            } else {
                $html[] = $this->option($space . $display, $value, $selected, $optionAttributes);
            }
        }
        return $this->toHtmlString('<optgroup label="' . e($space . $label, false) . '"' . $this->html->attributes($attributes) . '>' . implode('', $html) . '</optgroup>');
    }

    /**
     * Create a select element option.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @param  array  $attributes
     *
     * @return \Illuminate\Support\HtmlString
     */
    protected function option(string $display, $value, $selected, array $attributes = []): HtmlString
    {
        $selected = $this->getSelectedValue($value, $selected);

        $options = array_merge(['value' => $value, 'selected' => $selected], $attributes);

        $string = '<option' . $this->html->attributes($options) . '>';

        if ($display !== null) {
            $string .= e($display, false) . '</option>';
        }

        return $this->toHtmlString($string);
    }

    /**
     * Create a placeholder select element option.
     *
     * @param $display
     * @param $selected
     *
     * @return \Illuminate\Support\HtmlString
     */
    protected function placeholderOption($display, $selected): HtmlString
    {
        $selected = $this->getSelectedValue(null, $selected);

        $options = [
            'selected' => $selected,
            'value' => '',
        ];

        return $this->toHtmlString('<option' . $this->html->attributes($options) . '>' . e($display, false) . '</option>');
    }

    /**
     * Determine if the value is selected.
     *
     * @param  string $value
     * @param  string $selected
     *
     * @return null|string
     */
    protected function getSelectedValue($value, $selected): ?string
    {
        if (is_array($selected)) {
            return in_array($value, $selected, true) || in_array((string) $value, $selected, true) ? 'selected' : null;
        } elseif ($selected instanceof Collection) {
            return $selected->contains($value) ? 'selected' : null;
        }

        if (is_int($value) && is_bool($selected)) {
            return (bool) $value === $selected ? 'selected' : null;
        }

        return ((string) $value === (string) $selected) ? 'selected' : null;
    }

    /**
     * Create a checkbox input field.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function checkbox(string $name, $value = 1, ?bool $checked = null, array $options = []): HtmlString
    {
        return $this->checkable('checkbox', $name, $value, $checked, $options);
    }

    /**
     * Create a radio button input field.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function radio(string $name, $value = null, ?bool $checked = null, array $options = []): HtmlString
    {
        if (is_null($value)) {
            $value = $name;
        }

        return $this->checkable('radio', $name, $value, $checked, $options);
    }

    /**
     * Create a checkable input field.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    protected function checkable(string $type, string $name, $value, ?bool $checked, array $options): HtmlString
    {
        $this->type = $type;

        $checked = $this->getCheckedState($type, $name, $value, $checked);

        if ($checked) {
            $options['checked'] = 'checked';
        }

        return $this->input($type, $name, $value, $options);
    }

    /**
     * Get the check state for a checkable input.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getCheckedState(string $type, string $name, $value, ?bool $checked): bool
    {
        switch ($type) {
            case 'checkbox':
                return (bool) $this->getCheckboxCheckedState($name, $value, $checked);

            case 'radio':
                return (bool) $this->getRadioCheckedState($name, $value, $checked);

            default:
                return (bool) ($this->getValueAttribute($name) == $value);
        }
    }

    /**
     * Get the check state for a checkbox input.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getCheckboxCheckedState($name, $value, $checked)
    {
        if (isset($this->session) && ! $this->oldInputIsEmpty() && is_null($this->old($name))) {
            return false;
        }

        if ($this->missingOldAndModel($name)) {
            return $checked;
        }

        $posted = $this->getValueAttribute($name, $checked);

        if (is_array($posted)) {
            return in_array($value, $posted);
        } elseif ($posted instanceof Collection) {
            return $posted->contains('id', $value);
        } else {
            return (bool) $posted;
        }
    }

    /**
     * Get the check state for a radio input.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getRadioCheckedState($name, $value, $checked)
    {
        if ($this->missingOldAndModel($name)) {
            return $checked;
        }

        return $this->compareValues($name, $value);
    }

    /**
     * Determine if the provide value loosely compares to the value assigned to the field.
     * Use loose comparison because Laravel model casting may be in affect and therefore
     * 1 == true and 0 == false.
     *
     * @param  string $name
     * @param  string $value
     * @return bool
     */
    protected function compareValues($name, $value)
    {
        return $this->getValueAttribute($name) == $value;
    }

    /**
     * Determine if old input or model input exists for a key.
     *
     * @param  string $name
     *
     * @return bool
     */
    protected function missingOldAndModel(string $name): bool
    {
        $missing = (is_null($this->old($name)) && is_null($this->getModelValueAttribute($name)));

        if ($this->considerRequest && $missing) {
            return is_null($this->request($name));
        }

        return $missing;
    }

    /**
     * Create a HTML reset input element.
     *
     * @param  string|null $value
     * @param  array  $attributes
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function reset(?string $value, array $attributes = []): HtmlString
    {
        return $this->input('reset', null, $value, $attributes);
    }

    /**
     * Create a HTML image input element.
     *
     * @param  string $url
     * @param  string|null $name
     * @param  array  $attributes
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function image(string $url, ?string $name = null, array $attributes = []): HtmlString
    {
        $attributes['src'] = $this->url->asset($url);

        return $this->input('image', $name, null, $attributes);
    }

    /**
     * Create a month input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function month(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('month', $name, $value, $options);
    }

    /**
     * Create a color input field.
     *
     * @param  string $name
     * @param  string|null $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function color(string $name, ?string $value = null, array $options = []): HtmlString
    {
        return $this->input('color', $name, $value, $options);
    }

    /**
     * Create a submit button element.
     *
     * @param  string|null $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function submit(?string $value = null, array $options = []): HtmlString
    {
        return $this->input('submit', null, $value, $options);
    }

    /**
     * Create a button element.
     *
     * @param  string|null $value
     * @param  array  $options
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function button(?string $value = null, array $options = []): HtmlString
    {
        if (! array_key_exists('type', $options)) {
            $options['type'] = 'button';
        }

        // Apply theme classes if active
        $themeClass = $this->html->getThemeClass('button');
        if ($themeClass) {
            $options['class'] = trim(($options['class'] ?? '') . ' ' . $themeClass);
        }

        return $this->toHtmlString('<button' . $this->html->attributes($options) . '>' . $value . '</button>');
    }

    /**
     * Create a datalist box field.
     *
     * @param  string $id
     * @param  array  $list
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function datalist(string $id, iterable $list = []): HtmlString
    {
        $this->type = 'datalist';

        $attributes['id'] = $id;

        $html = [];

        if ($this->isAssociativeArray($list)) {
            foreach ($list as $value => $display) {
                $html[] = $this->option($display, (string) $value, null, []);
            }
        } else {
            foreach ($list as $value) {
                $html[] = $this->option((string) $value, (string) $value, null, []);
            }
        }

        $attributes = $this->html->attributes($attributes);

        $list = implode('', $html);

        return $this->toHtmlString("<datalist{$attributes}>{$list}</datalist>");
    }

    /**
     * Determine if an array is associative.
     *
     * @param  array $array
     * @return bool
     */
    protected function isAssociativeArray(array $array): bool
    {
        return (array_values($array) !== $array);
    }

    /**
     * Parse the form action method.
     *
     * @param  string $method
     *
     * @return string
     */
    protected function getMethod(string $method): string
    {
        $method = strtoupper($method);

        return $method !== 'GET' ? 'POST' : $method;
    }

    /**
     * Get the form action from the options.
     *
     * @param  array $options
     *
     * @return string
     */
    protected function getAction(array $options): string
    {
        // We will also check for a "route" or "action" parameter on the array so that
        // developers can easily specify a route or controller action when creating
        // a form providing a convenient interface for creating the form actions.
        if (isset($options['url'])) {
            return $this->getUrlAction($options['url']);
        }

        if (isset($options['route'])) {
            return $this->getRouteAction($options['route']);
        }

        // If an action is available, we are attempting to open a form to a controller
        // action route. So, we will use the URL generator to get the path to these
        // actions and return them from the method. Otherwise, we'll use current.
        elseif (isset($options['action'])) {
            return $this->getControllerAction($options['action']);
        }

        return $this->url->current();
    }

    /**
     * Get the action for a "url" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getUrlAction($options): string
    {
        if (is_array($options)) {
            return $this->url->to($options[0], array_slice($options, 1));
        }

        return $this->url->to($options);
    }

    /**
     * Get the action for a "route" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getRouteAction($options): string
    {
        if (is_array($options)) {
            $parameters = array_slice($options, 1);

            if (array_keys($options) === [0, 1]) {
                $parameters = \head($parameters);
            }

            return $this->url->route($options[0], $parameters);
        }

        return $this->url->route($options);
    }

    /**
     * Get the action for an "action" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getControllerAction($options): string
    {
        if (is_array($options)) {
            return $this->url->action($options[0], array_slice($options, 1));
        }

        return $this->url->action($options);
    }

    /**
     * Get the form appendage for the given method.
     *
     * @param  string $method
     *
     * @return string
     */
    protected function getAppendage(string $method): string
    {
        list($method, $appendage) = [strtoupper($method), ''];

        // If the HTTP method is in this list of spoofed methods, we will attach the
        // method spoofer hidden input to the form. This allows us to use regular
        // form to initiate PUT and DELETE requests in addition to the typical.
        if (in_array($method, $this->spoofedMethods)) {
            $appendage .= $this->hidden('_method', $method);
        }

        // If the method is something other than GET we will go ahead and attach the
        // CSRF token to the form, as this can't hurt and is convenient to simply
        // always have available on every form the developers creates for them.
        if ($method !== 'GET') {
            $appendage .= $this->token();
        }

        return (string) $appendage;
    }

    /**
     * Get the ID attribute for a field name.
     *
     * @param  string|null $name
     * @param  array  $attributes
     *
     * @return string|null
     */
    public function getIdAttribute(?string $name, array $attributes): ?string
    {
        if (array_key_exists('id', $attributes)) {
            return $attributes['id'];
        }

        if (in_array($name, $this->labels)) {
            return $name;
        }

        return null;
    }

    /**
     * Get the value that should be assigned to the field.
     *
     * @param  string|null $name
     * @param  mixed $value
     *
     * @return mixed
     */
    public function getValueAttribute(?string $name, mixed $value = null): mixed
    {
        if (is_null($name)) {
            return $value;
        }

        $old = $this->old($name);

        if (! is_null($old) && $name !== '_method') {
            return $old;
        }

        if (function_exists('app')) {
            $hasOldInput = ! is_null($this->session) && $this->session->has('_old_input');

            if ($hasOldInput && is_null($old) && is_null($value)) {
                return null;
            }
        }

        $request = $this->request($name);
        if (! is_null($request) && $name != '_method') {
            return $request;
        }

        if (! is_null($value)) {
            return $value;
        }

        if (isset($this->model)) {
            return $this->getModelValueAttribute($name);
        }

        return null;
    }

    /**
     * Take Request in fill process
     *
     * @param bool $consider
     *
     * @return void
     */
    public function considerRequest(bool $consider = true): void
    {
        $this->considerRequest = $consider;
    }

    /**
     * Set the active CSS framework theme.
     *
     * @param  string|null $theme
     *
     * @return $this
     */
    public function theme(?string $theme): self
    {
        $this->html->theme($theme);

        return $this;
    }

    /**
     * Set the active CSS framework theme to Tailwind.
     *
     * @return $this
     */
    public function tailwind(): self
    {
        return $this->theme('tailwind');
    }

    /**
     * Set the active CSS framework theme to Bootstrap.
     *
     * @return $this
     */
    /**
     * Pending attributes to be applied to the next element.
     *
     * @var array
     */
    protected array $pendingAttributes = [];

    /**
     * Wrap the input element with an icon.
     *
     * @param  string $input
     * @param  string $icon
     * @return string
     */
    protected function wrapWithIcon(string $input, string $icon): string
    {
        $theme = $this->html->getTheme();

        if ($theme === 'tailwind') {
            return '<div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <span class="text-gray-500 sm:text-sm">' . $icon . '</span>
                </div>' . str_replace('class="', 'class="pl-10 ', $input) . '
            </div>';
        }

        if ($theme === 'bootstrap') {
            return '<div class="input-group">
                <span class="input-group-text">' . $icon . '</span>
                ' . $input . '
            </div>';
        }

        return '<div class="input-icon">' . $icon . $input . '</div>';
    }

    public function bootstrap(): self
    {
        return $this->theme('bootstrap');
    }

    /**
     * Create a floating label form group.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed  $value
     * @param  array  $options
     * @param  string|null $label
     * @return \Illuminate\Support\HtmlString
     */
    public function floating(string $type, string $name, $value = null, array $options = [], ?string $label = null): HtmlString
    {
        $theme = $this->html->getTheme();
        $label = $label ?: $this->formatLabel($name, $label);

        if ($theme === 'bootstrap') {
            $options['placeholder'] = $options['placeholder'] ?? ' '; // Required for BS floating labels
            $input = $this->input($type, $name, $value, $options);
            $labelHtml = $this->label($name, $label);

            return $this->toHtmlString('<div class="form-floating mb-3">' . $input . $labelHtml . '</div>');
        }

        if ($theme === 'tailwind') {
            $options['placeholder'] = $options['placeholder'] ?? ' ';
            $inputClass = 'peer placeholder-transparent';
            $options['class'] = trim(($options['class'] ?? '') . ' ' . $inputClass);

            $input = $this->input($type, $name, $value, $options);
            $labelHtml = $this->label($name, $label, [
                'class' => 'absolute left-4 -top-6 text-sm text-gray-600 transition-all peer-placeholder-shown:text-base peer-placeholder-shown:text-gray-440 peer-placeholder-shown:top-2 peer-focus:-top-6 peer-focus:text-gray-600 peer-focus:text-sm'
            ]);

            return $this->toHtmlString('<div class="relative mt-6">' . $input . $labelHtml . '</div>');
        }

        // Fallback for simple themes
        return $this->toHtmlString('<div>' . $this->label($name, $label) . $this->input($type, $name, $value, $options) . '</div>');
    }

    /**
     * Add an Alpine.js attribute to the next element.
     *
     * @param string $attribute
     * @param string $expression
     * @return $this
     */
    public function alpine(string $attribute, string $expression): self
    {
        $this->pendingAttributes["x-$attribute"] = $expression;

        return $this;
    }

    /**
     * Add a Vue.js attribute to the next element.
     *
     * @param string $attribute
     * @param string $expression
     * @return $this
     */
    public function vue(string $attribute, string $expression): self
    {
        $this->pendingAttributes["v-$attribute"] = $expression;

        return $this;
    }

    /**
     * Consume and return pending attributes.
     *
     * @return array
     */
    protected function consumePendingAttributes(): array
    {
        $attributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        return $attributes;
    }

    /**
     * Add a Livewire wire:model attribute to the next element.
     *
     * @param string $property
     * @return $this
     */
    public function wire(string $property): self
    {
        $this->pendingAttributes['wire:model'] = $property;

        return $this;
    }

    /**
     * Add a Livewire wire:model.lazy attribute to the next element.
     *
     * @param string $property
     * @return $this
     */
    public function wireLazy(string $property): self
    {
        $this->pendingAttributes['wire:model.lazy'] = $property;

        return $this;
    }

    /**
     * Add a Livewire wire:model.defer attribute to the next element.
     *
     * @param string $property
     * @return $this
     */
    public function wireDefer(string $property): self
    {
        $this->pendingAttributes['wire:model.defer'] = $property;

        return $this;
    }

    /**
     * Add a Livewire wire:model.live attribute to the next element.
     *
     * @param string $property
     * @param int|null $debounce Debounce time in milliseconds
     * @return $this
     */
    public function wireLive(string $property, ?int $debounce = null): self
    {
        $modifier = 'wire:model.live';

        if ($debounce !== null) {
            $modifier .= ".debounce.{$debounce}ms";
        }

        $this->pendingAttributes[$modifier] = $property;

        return $this;
    }

    /**
     * Add a Livewire wire:click attribute to the next element.
     *
     * @param string $method
     * @return $this
     */
    public function wireClick(string $method): self
    {
        $this->pendingAttributes['wire:click'] = $method;

        return $this;
    }

    /**
     * Add a Livewire wire:submit attribute to the form.
     *
     * @param string $method
     * @return $this
     */
    public function wireSubmit(string $method): self
    {
        $this->pendingAttributes['wire:submit'] = $method;

        return $this;
    }

    /**
     * Add a Livewire wire:submit.prevent attribute to the form.
     *
     * @param string $method
     * @return $this
     */
    public function wireSubmitPrevent(string $method): self
    {
        $this->pendingAttributes['wire:submit.prevent'] = $method;

        return $this;
    }

    /**
     * Set validation rules for the next input field.
     *
     * @param string|array $rules Laravel validation rules
     * @return $this
     */
    public function rules(string|array $rules): self
    {
        $this->pendingRules = $rules;

        return $this;
    }

    /**
     * Parse Laravel validation rules into HTML5 attributes.
     *
     * @param string|array $rules
     * @param array $options
     * @return array
     */
    protected function parseValidationRules(string|array $rules, array $options): array
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            // Handle rules with parameters
            if (str_contains($rule, ':')) {
                [$ruleName, $parameters] = explode(':', $rule, 2);
                $parameters = explode(',', $parameters);
            } else {
                $ruleName = $rule;
                $parameters = [];
            }

            $ruleName = trim($ruleName);

            // Convert Laravel rules to HTML5 attributes
            switch ($ruleName) {
                case 'required':
                    $options['required'] = true;
                    break;

                case 'email':
                    $options['type'] = $options['type'] ?? 'email';
                    break;

                case 'url':
                    $options['type'] = $options['type'] ?? 'url';
                    break;

                case 'numeric':
                case 'integer':
                    $options['type'] = $options['type'] ?? 'number';
                    break;

                case 'min':
                    if (isset($parameters[0])) {
                        if (($options['type'] ?? 'text') === 'number') {
                            $options['min'] = $parameters[0];
                        } else {
                            $options['minlength'] = $parameters[0];
                        }
                    }
                    break;

                case 'max':
                    if (isset($parameters[0])) {
                        if (($options['type'] ?? 'text') === 'number') {
                            $options['max'] = $parameters[0];
                        } else {
                            $options['maxlength'] = $parameters[0];
                        }
                    }
                    break;

                case 'between':
                    if (isset($parameters[0], $parameters[1])) {
                        if (($options['type'] ?? 'text') === 'number') {
                            $options['min'] = $parameters[0];
                            $options['max'] = $parameters[1];
                        }
                    }
                    break;

                case 'regex':
                    if (isset($parameters[0])) {
                        $options['pattern'] = trim($parameters[0], '/');
                    }
                    break;
            }
        }

        return $options;
    }

    /**
     * Apply pending rules to options if set.
     *
     * @param array $options
     * @return array
     */
    protected function applyPendingRules(array $options): array
    {
        if ($this->pendingRules !== null) {
            $options = $this->parseValidationRules($this->pendingRules, $options);
            $this->pendingRules = null;
        }

        return $options;
    }

    /**
     * Create a toggle/switch input.
     *
     * @param string $name
     * @param mixed $value
     * @param bool $checked
     * @param array $options
     * @return \Illuminate\Support\HtmlString
     */
    public function toggle(string $name, $value = 1, bool $checked = false, array $options = []): HtmlString
    {
        $theme = $this->html->getTheme();

        if ($theme === 'bootstrap') {
            $options['class'] = trim(($options['class'] ?? '') . ' form-check-input');
            $options['role'] = 'switch';

            $checked = $this->getCheckedState('checkbox', $name, $value, $checked);

            $html = '<div class="form-check form-switch">';
            $html .= $this->checkbox($name, $value, $checked, $options);

            if (isset($options['label'])) {
                $html .= $this->label($name, $options['label'], ['class' => 'form-check-label']);
            }

            $html .= '</div>';

            return $this->toHtmlString($html);
        } elseif ($theme === 'tailwind') {
            $checked = $this->getCheckedState('checkbox', $name, $value, $checked);
            $id = $this->getIdAttribute($name, $options);

            $html = '<label class="inline-flex items-center cursor-pointer">';
            $html .= '<input type="checkbox" name="' . e($name) . '" value="' . e($value) . '" id="' . e($id) . '"';
            $html .= ' class="sr-only peer"';

            if ($checked) {
                $html .= ' checked';
            }

            $html .= $this->html->attributes($options);
            $html .= '>';
            $html .= '<div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\'\'] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>';

            if (isset($options['label'])) {
                $html .= '<span class="ml-3 text-sm font-medium text-gray-900">' . e($options['label']) . '</span>';
            }

            $html .= '</label>';

            return $this->toHtmlString($html);
        } else {
            // Fallback to regular checkbox
            return $this->checkbox($name, $value, $checked, $options);
        }
    }

    /**
     * Generate a honeypot field for anti-spam protection.
     *
     * @param  string|null $name
     * @param  string|null $time_name
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function honeypot(?string $name = null, ?string $time_name = null): HtmlString
    {
        if (is_null($name)) {
            $name = \config('html.honeypot.name', 'my_name');
        }

        if (is_null($time_name)) {
            $time_name = \config('html.honeypot.time_name', 'my_time');
        }

        $html = '<div style="display:none;">';
        $html .= (string) $this->text($name, '', ['id' => $name, 'tabindex' => -1, 'autocomplete' => 'off']);
        $html .= (string) $this->hidden($time_name, time());
        $html .= '</div>';

        return $this->toHtmlString($html);
    }

    /**
     * Get value from current Request
     *
     * @param string|null $name
     *
     * @return array|null|string
     */
    protected function request(?string $name): array|null|string
    {
        if (!$this->considerRequest) {
            return null;
        }

        if (!isset($this->request)) {
            return null;
        }

        return $this->request->input($this->transformKey($name));
    }

    /**
     * Get the model value that should be assigned to the field.
     *
     * @param  string $name
     *
     * @return mixed
     */
    protected function getModelValueAttribute(string $name): mixed
    {
        $key = $this->transformKey($name);

        if ((is_string($this->model) || is_object($this->model)) && method_exists($this->model, 'getFormValue')) {
            return $this->model->getFormValue($key);
        }

        return \data_get($this->model, $key);
    }

    /**
     * Get a value from the session's old input.
     *
     * @param  string $name
     *
     * @return mixed
     */
    public function old(string $name): mixed
    {
        if (isset($this->session)) {
            $key = $this->transformKey($name);
            $payload = $this->session->get('_old_input', []);
            $value = Arr::get($payload, $key);

            if (!is_array($value)) {
                return $value;
            }

            if (!in_array($this->type, ['select', 'checkbox'])) {
                if (!isset($this->payload[$key])) {
                    $this->payload[$key] = \collect($value);
                }

                if (!empty($this->payload[$key])) {
                    $item = $this->payload[$key]->shift();
                    return $item;
                }
            }

            return $value;
        }

        return null;
    }

    /**
     * Determine if the old input is empty.
     *
     * @return bool
     */
    public function oldInputIsEmpty(): bool
    {
        return (isset($this->session) && count((array) $this->session->get('_old_input', [])) === 0);
    }

    /**
     * Transform key from array to dot syntax.
     *
     * @param  string|null $key
     *
     * @return string
     */
    protected function transformKey(?string $key): string
    {
        return str_replace(['.', '[]', '[', ']'], ['_', '', '.', ''], (string) $key);
    }

    /**
     * Transform the string to an Html serializable object
     *
     * @param mixed $html
     *
     * @return \Illuminate\Support\HtmlString
     */
    protected function toHtmlString($html): HtmlString
    {
        return new HtmlString($html);
    }

    /**
     * Get the session store implementation.
     *
     * @return  \Illuminate\Contracts\Session\Session|null  $session
     */
    public function getSessionStore(): ?Session
    {
        return $this->session;
    }

    /**
     * Set the session store implementation.
     *
     * @param  \Illuminate\Contracts\Session\Session $session
     *
     * @return $this
     */
    public function setSessionStore(Session $session): self
    {
        $this->session = $session;

        return $this;
    }

    /**
     * Add accessibility (A11y) attributes to the options array.
     *
     * @param  array $options
     *
     * @return array
     */
    protected function addA11yAttributes(array $options): array
    {
        // Automatically add aria-required="true" if the required attribute is present.
        if (isset($options['required']) && ! isset($options['aria-required'])) {
            $options['aria-required'] = 'true';
        }

        // Add aria-invalid="true" if there are errors for this field.
        if (isset($options['name']) && $this->hasError($options['name'])) {
            $options['aria-invalid'] = 'true';
        }

        return $options;
    }

    /**
     * Get the Enum list for the given Enum class.
     *
     * @param  string $enum
     * @return array
     */
    protected function getEnumList(string $enum): array
    {
        $list = [];

        foreach ($enum::cases() as $case) {
            $value = $case instanceof \BackedEnum ? $case->value : $case->name;
            $list[$value] = $case->name;
        }

        return $list;
    }

    /**
     * Set the error bag instance.
     *
     * @param  mixed $errors
     * @return $this
     */
    public function setErrorBag($errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * Determine if the given field has an error.
     *
     * @param  string $name
     * @return bool
     */
    public function hasError(?string $name): bool
    {
        if (is_null($name) || ! isset($this->errors)) {
            return false;
        }

        $key = $this->transformKey($name);

        return $this->errors->has($key);
    }

    /**
     * Get the error CSS class based on the active theme.
     *
     * @return string|null
     */
    protected function getErrorClass(): ?string
    {
        $theme = $this->html->getTheme();

        return match ($theme) {
            'bootstrap' => 'is-invalid',
            'tailwind' => 'border-red-500',
            default => null,
        };
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string $method
     * @param  array  $parameters
     *
     * @return \Illuminate\Contracts\View\View|mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if (static::hasComponent($method)) {
            return $this->componentCall($method, $parameters);
        }

        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        throw new BadMethodCallException("Method {$method} does not exist.");
    }
}
