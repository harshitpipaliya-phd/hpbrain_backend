# Template Inheritance

## Resolution Chain
1. Platform default
2. Industry template
3. Organization override
4. Role override
5. User preference

## Override Storage
`hpbrain_template_overrides` stores overrides by `template_type`, `template_key`, and `override_level`.

## Engine
`App\Services\TemplateInheritanceEngine` resolves values across the chain. The `resolve()` method returns the most specific active override.

## Supported Template Types
- terminology
- modules
- navigation
- dashboards
- branding
- workflows
- integrations
