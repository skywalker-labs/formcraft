<div align="center">

```
╔═══════════════════════════════════════════════════════════════╗
║                                                               ║
║   ███████╗ ██████╗ ██████╗ ███╗   ███╗ ██████╗██████╗  █████╗ ║
║   ██╔════╝██╔═══██╗██╔══██╗████╗ ████║██╔════╝██╔══██╗██╔══██╗║
║   █████╗  ██║   ██║██████╔╝██╔████╔██║██║     ██████╔╝███████║║
║   ██╔══╝  ██║   ██║██╔══██╗██║╚██╔╝██║██║     ██╔══██╗██╔══██║║
║   ██║     ╚██████╔╝██║  ██║██║ ╚═╝ ██║╚██████╗██║  ██║██║  ██║║
║   ╚═╝      ╚═════╝ ╚═╝  ╚═╝╚═╝     ╚═╝ ╚═════╝╚═╝  ╚═╝╚═╝  ╚═╝║
║                                                               ║
║            HTML & Form Builder for Laravel                    ║
║       🎨 50+ Components | 🚀 Modern | ♿ Accessible          ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

</div>

# FormCraft - Laravel HTML & Form Builder

[![Latest Version on Packagist](https://img.shields.io/packagist/v/skywalker-labs/formcraft.svg?style=flat-square)](https://packagist.org/packages/skywalker-labs/formcraft)
[![Total Downloads](https://img.shields.io/packagist/dt/skywalker-labs/formcraft.svg?style=flat-square)](https://packagist.org/packages/skywalker-labs/formcraft)

A comprehensive HTML and Form Builder for Laravel with **50+ modern features**, strict PHP 8.2+ typing, and built-in support for Bootstrap & Tailwind CSS.

## ✨ Features

- 🎨 **Theme Support** - Bootstrap & Tailwind CSS
- 🔄 **Livewire Compatible** - Built-in wire:model helpers
- ♿ **Accessibility Ready** - Automatic ARIA attributes
- 📱 **Responsive** - Mobile-first design
- 🚀 **50+ Components** - Forms, UI elements, utilities
- 🎯 **Zero Dependencies** - No external JavaScript required

## 📦 Installation

```bash
composer require skywalker-labs/formcraft
```

Publish configuration:

```bash
php artisan vendor:publish --tag=html-config
```

## 🚀 Quick Start

### Basic Form

```php
{!! Form::open(['url' => 'users/store']) !!}
    {!! Form::text('name') !!}
    {!! Form::email('email') !!}
    {!! Form::submit('Save') !!}
{!! Form::close() !!}
```

### Model Binding

```php
{!! Form::model($user, ['route' => ['users.update', $user]]) !!}
    {!! Form::text('name') !!}
    {!! Form::email('email') !!}
    {!! Form::submit('Update') !!}
{!! Form::close() !!}
```

### Blade Components

```blade
<x-form-input name="email" label="Email" />
<x-form-select name="country" :options="$countries" />
<x-form-textarea name="bio" rows="5" />
<x-form-checkbox name="agree" label="I agree" />
```

## 📚 Key Features

### HTML5 Validation Rules

```php
{!! Form::rules('required|email|max:255')->text('email') !!}
{!! Form::rules('required|min:8')->password('password') !!}
```

### Smart Components

```php
// Toggle switch
{!! Form::toggle('notifications', 1, true, ['label' => 'Enable']) !!}

// Color picker
{!! Form::colorPicker('theme_color', '#3490dc') !!}

// Searchable select
{!! Form::searchableSelect('country', $countries) !!}

// Date picker
{!! Form::datePicker('birthday') !!}

// File upload with preview
{!! Form::fileUpload('avatar', ['preview' => true]) !!}
```

### Livewire Integration

```php
{!! Form::wire('email')->text('email') !!}
{!! Form::wireLive('search', 300)->text('search') !!}
{!! Form::wireClick('save')->submit('Save') !!}
```

### UI Components

```php
{!! Html::alert('Success!', 'success') !!}
{!! Html::progressBar(75, ['striped' => true]) !!}
{!! Html::badge('New', 'primary') !!}
{!! Html::tabs(['Tab 1' => 'Content 1', 'Tab 2' => 'Content 2']) !!}
```

## 📖 Documentation

Complete documentation available in the repository:

- [Installation Guide](wiki/Installation.md)
- [Quick Start](wiki/Quick-Start.md)
- [API Reference](wiki/API-Reference.md)
- [Examples](wiki/Examples.md)
- [Livewire Integration](wiki/Livewire.md)

## 🎯 Feature List

**Phase 1-4:** Core features, utilities, UI components, Livewire  
**Phase 5:** Validation rules, toggles, color pickers, ratings  
**Phase 6:** Searchable/multi/AJAX selects, autocomplete  
**Phase 7:** Date/time pickers, file uploads  
**Phase 8:** Rich text editor, inline editing, form wizards  
**Phase 9:** Progress bars, badges, tabs, accordion, tooltips

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

The Laravel HTML package is open-sourced software licensed under the [MIT license](LICENSE.txt).


