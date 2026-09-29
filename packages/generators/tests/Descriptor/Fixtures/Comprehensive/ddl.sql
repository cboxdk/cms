-- The DDL of the type table of the comprehensive example, shop:product extended by app
-- (PRD 4.1, 11.6): the statements of its first migration, before its grants and row level
-- security. TypeTableMigrationsTest compares it with what the generator writes.

create table "shop__product" (
    cms_entry_id uuid not null references entries (id),
    cms_locale text not null
        check (cms_locale = 'shared'),
    cms_stage text not null
        check (cms_stage in ('released', 'draft', 'staged')),
    cms_home_node uuid not null references nodes (id),
    cms_owner_actor uuid references actors (id),
    "body" jsonb
        check (jsonb_typeof("body") = 'array'),
    "care" jsonb
        check (jsonb_typeof("care") = 'array'),
    "checked_at" timestamptz
        check ("checked_at" >= '2000-01-01T00:00:00Z'::timestamptz),
    "colour" text not null
        check ("colour" IN ('red', 'green', 'blue')),
    "dimensions" jsonb
        check (CASE WHEN jsonb_typeof("dimensions") = 'array' THEN jsonb_array_length("dimensions") BETWEEN 1 AND 5 ELSE "dimensions" IS NULL END),
    "discontinued" boolean,
    "ext__app__name" text
        check (char_length("ext__app__name") <= 255),
    "ext__app__tax_code" text
        check (char_length("ext__app__tax_code") <= 20),
    "launch_date" date
        check ("launch_date" >= '2000-01-01'::date)
        check ("launch_date" <= '2099-12-31'::date),
    "name" text not null
        check (char_length("name") >= 1)
        check (char_length("name") <= 120),
    "price" numeric(10, 2) not null
        check ("price" >= 0.00)
        check ("price" <= 99999999.99),
    "purchase_price" bytea,
    "stock" bigint
        check ("stock" >= 0)
        check ("stock" <= 1000000),
    "summary" text
        check (char_length("summary") <= 2000),
    "supplier" bytea,
    "support_email" text
        check (char_length("support_email") <= 255),
    "tags" text[]
        check ("tags" <@ ARRAY['new', 'sale', 'eco']::text[])
        check (cardinality("tags") >= 1)
        check (cardinality("tags") <= 3),
    "weight" bigint
        check ("weight" >= 1),
    primary key (cms_entry_id, cms_locale, cms_stage)
) with (fillfactor = 80);

create index "shop__product__cms_home_node" on "shop__product" (cms_home_node);

create index "shop__product__cms_owner_actor" on "shop__product" (cms_owner_actor);

create index "shop__product__colour" on "shop__product" (cms_stage, cms_locale, "colour", cms_entry_id);

create index "shop__product__discontinued" on "shop__product" (cms_stage, cms_locale, "discontinued", cms_entry_id);

create index "shop__product__ext__app__tax_code" on "shop__product" (cms_stage, cms_locale, "ext__app__tax_code", cms_entry_id);

create index "shop__product__launch_date" on "shop__product" (cms_stage, cms_locale, "launch_date", cms_entry_id);

create index "shop__product__name" on "shop__product" (cms_stage, cms_locale, "name", cms_entry_id);

create index "shop__product__price" on "shop__product" (cms_stage, cms_locale, "price", cms_entry_id);

create index "shop__product__stock" on "shop__product" (cms_stage, cms_locale, "stock", cms_entry_id);
