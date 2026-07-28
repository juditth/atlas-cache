# Atlas cache


# Project Philosophy

This plugin prioritizes simplicity over feature count.

Goals:

- predictable behavior
- transparent debugging
- modular architecture
- maintainability
- performance
- clean code

Non-goals:

- CSS optimization
- image optimization
- CDN
- object cache
- all-in-one optimization




# Development Rules

- Follow SOLID principles.
- Prefer composition over inheritance.
- Avoid singletons whenever possible.
- Use dependency injection.
- Follow PSR-4 autoloading.
- Every class should have a single responsibility.
- Keep WordPress-specific code isolated.
- Avoid hidden side effects.
- Prefer immutable value objects where appropriate.
- Public APIs must be documented.


Whenever multiple implementations are possible, always choose the simpler one unless it significantly reduces performance or extensibility.



Rule nr.1: Security beats performance!! The plugin must be unhackable!