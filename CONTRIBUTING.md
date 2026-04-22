# Contributing to WP Crontrol

Bug reports, code contributions, and general feedback are very welcome. These should be submitted through [the GitHub repository](https://github.com/johnbillion/wp-crontrol). Development happens in the `develop` branch, and any pull requests should be made against that branch please.

## Inclusivity and Code of Conduct

Contributions to WP Crontrol are welcome from anyone. Whether you are new to Open Source or a seasoned veteran, all constructive contribution is welcome and I'll endeavour to support you when I can.

This project is released with <a href="https://github.com/johnbillion/wp-crontrol/blob/develop/CODE_OF_CONDUCT.md">a contributor code of conduct</a> and by participating in this project you agree to abide by its terms. The code of conduct is nothing to worry about, if you are a respectful human being then all will be good.

## Setting up Locally

You can clone this repo and activate it like a normal WordPress plugin. If you want to contribute to WP Crontrol, you should install the developer dependencies in order to run the tests.

### Prerequisites

* [Composer](https://getcomposer.org/)
* [Node.js](https://nodejs.org/) version 24
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or compatible) to run the tests

### Setup

Install the PHP dependencies:

	composer install

Install the Node.js dependencies:

	npm install

## Running the Tests

The test suite consists of:

* Acceptance tests using Playwright
* Integration tests using PHPUnit
* Linting using PHPCS
* Static analysis using PHPStan

The acceptance and integration tests run in a container. Ensure Docker Desktop is running before running the tests.

To run the whole test suite:

	composer test

To run tests individually, run one of:

	composer test:phpcs
	composer test:phpstan
	composer test:integration
	composer test:acceptance

To run a single test:

	composer test:acceptance -- tests/acceptance/AddEvent.spec.ts

The individual integration and acceptance tests require the Docker containers to be running. To start and stop them, use:

	composer test:start
	composer test:stop
