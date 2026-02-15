<?php

namespace Skywalker\Html;

use Illuminate\Contracts\Support\DeferrableProvider;
use Skywalker\Support\Providers\PackageServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

class HtmlServiceProvider extends PackageServiceProvider implements DeferrableProvider
{
    /**
     * Vendor name.
     *
     * @var string
     */
    protected $vendor = 'skywalker';

    /**
     * Package name.
     *
     * @var string
     */
    protected $package = 'html';
    /**
     * Supported Blade Directives
     *
     * @var array
     */
    protected $directives = [
        'entities',
        'decode',
        'script',
        'style',
        'image',
        'favicon',
        'link',
        'secureLink',
        'linkAsset',
        'linkSecureAsset',
        'linkRoute',
        'linkAction',
        'mailto',
        'email',
        'ol',
        'ul',
        'dl',
        'meta',
        'tag',
        'open',
        'model',
        'close',
        'token',
        'label',
        'input',
        'text',
        'password',
        'hidden',
        'email',
        'tel',
        'number',
        'date',
        'datetime',
        'datetimeLocal',
        'time',
        'url',
        'file',
        'textarea',
        'select',
        'selectRange',
        'selectYear',
        'selectMonth',
        'getSelectOption',
        'checkbox',
        'radio',
        'reset',
        'image',
        'color',
        'submit',
        'button',
        'old'
    ];

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        $this->registerConfig();

        $this->registerHtmlBuilder();

        $this->registerFormBuilder();

        $this->app->alias('html', HtmlBuilder::class);
        $this->app->alias('form', FormBuilder::class);

        $this->registerBladeDirectives();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        $this->publishAll();

        $this->loadViews();

        // Register Blade components with 'form' prefix
        $this->app->afterResolving(BladeCompiler::class, function (BladeCompiler $blade) {
            $blade->component('html::components.input', 'form-input');
            $blade->component('html::components.select', 'form-select');
            $blade->component('html::components.textarea', 'form-textarea');
            $blade->component('html::components.checkbox', 'form-checkbox');
            $blade->component('html::components.radio', 'form-radio');
        });
    }

    /**
     * Register the HTML builder instance.
     *
     * @return void
     */
    protected function registerHtmlBuilder()
    {
        $this->app->singleton('html', function ($app) {
            return new HtmlBuilder($app['url'], $app['view']);
        });
    }

    /**
     * Register the form builder instance.
     *
     * @return void
     */
    protected function registerFormBuilder()
    {
        $this->app->singleton('form', function ($app) {
            $form = new FormBuilder($app['html'], $app['url'], $app['view'], $app['session.store']->token(), $app['request']);

            return $form->setSessionStore($app['session.store']);
        });
    }

    /**
     * Register Blade directives.
     *
     * @return void
     */
    protected function registerBladeDirectives(): void
    {
        $this->app->afterResolving('blade.compiler', function (BladeCompiler $bladeCompiler) {
            $namespaces = [
                'Html' => get_class_methods(HtmlBuilder::class),
                'Form' => get_class_methods(FormBuilder::class),
            ];

            foreach ($namespaces as $namespace => $methods) {
                foreach ($methods as $method) {
                    if (in_array($method, $this->directives)) {
                        $snakeMethod = Str::snake($method);
                        $directive = strtolower($namespace) . '_' . $snakeMethod;

                        $bladeCompiler->directive($directive, function ($expression) use ($namespace, $method) {
                            return "<?php echo $namespace::$method($expression); ?>";
                        });
                    }
                }
            }
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['html', 'form', HtmlBuilder::class, FormBuilder::class];
    }
}
