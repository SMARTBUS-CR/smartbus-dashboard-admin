---
paths:
  - 'app/Models/**'
---

# Models

## Use PHP 8 Eloquent Attributes for Model Configuration
Use PHP 8 Eloquent attributes (such as #[Connection('...')]], #[Fillable([...])], #[Table('...')]], #[Hidden([...])]) from Illuminate\Database\Eloquent\Attributes\* to configure Eloquent models.

## Use UUID Primary Keys for Models
Use UUID primary keys across Eloquent models via the HasUuids trait.

## Database Connection Scoping Strategy
PostgreSQL is the default database connection. Avoid adding explicit connection scopes on PostgreSQL models unless required by framework limitations (e.g. cross-database pivot models like CompanyUser). Use explicit connection attributes (such as #[Connection('mysql')]) on non-default database models.
