<?php

namespace Skywalker\Html;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class A11yTest extends TestCase
{
    protected $formBuilder;
    protected $urlGenerator;
    protected $viewFactory;
    protected $htmlBuilder;

    protected function setUp(): void
    {
        $this->urlGenerator = new UrlGenerator(new RouteCollection(), Request::create('/foo', 'GET'));
        $this->viewFactory = m::mock(Factory::class);
        $this->htmlBuilder = new HtmlBuilder($this->urlGenerator, $this->viewFactory);
        $this->formBuilder = new FormBuilder($this->htmlBuilder, $this->urlGenerator, $this->viewFactory, 'abc');
    }

    public function testAriaRequiredIsAddedToInput()
    {
        $input = $this->formBuilder->text('name', null, ['required' => 'required']);
        $this->assertStringContainsString('required="required"', (string) $input);
        $this->assertStringContainsString('aria-required="true"', (string) $input);
    }

    public function testAriaRequiredIsNotAddedIfAlreadyPresent()
    {
        $input = $this->formBuilder->text('name', null, ['required' => 'required', 'aria-required' => 'false']);
        $this->assertStringContainsString('required="required"', (string) $input);
        $this->assertStringContainsString('aria-required="false"', (string) $input);
        $this->assertStringNotContainsString('aria-required="true"', (string) $input);
    }

    public function testAriaRequiredIsAddedToTextarea()
    {
        $textarea = $this->formBuilder->textarea('bio', null, ['required' => 'required']);
        $this->assertStringContainsString('required="required"', (string) $textarea);
        $this->assertStringContainsString('aria-required="true"', (string) $textarea);
    }

    public function testAriaRequiredIsAddedToSelect()
    {
        $select = $this->formBuilder->select('size', ['L' => 'Large', 'S' => 'Small'], null, ['required' => 'required']);
        $this->assertStringContainsString('required="required"', (string) $select);
        $this->assertStringContainsString('aria-required="true"', (string) $select);
    }
}
