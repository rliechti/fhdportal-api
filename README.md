# FEGA Switzerland API

An API for the Swiss node of the Federated EGA (European Genome-phenome Archive).

## Getting Started

### Installation

Make sure you have PHP, Composer and  the [symfony-cli tools](https://symfony.com/download) installed on your system.

Clone the project repository:

```sh
git clone https://gitlab.sib.swiss/fega/fega-api.git
```

Navigate to the project directory:

```sh
cd fega-api
```

Install the project dependencies:

```sh
composer install
```

### Running the API

Start the local development server:

```sh
symfony server:start
```

To access the API, use the provided URL or run the following command:

```sh
symfony open:local
```
