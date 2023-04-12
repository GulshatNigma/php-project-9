CREATE TABLE urls (
    id integer UNIQUE PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name varchar(255) NOT NULL,
    created_at timestamp NOT NULL
);

CREATE TABLE url_checks (
    id integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    url_id integer REFERENCES urls(id),
    status_code integer,
    h1 varchar(255),
    title varchar(255),
    description text,
    created_at timestamp NOT NULL
);