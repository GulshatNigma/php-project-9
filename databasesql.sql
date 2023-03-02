truncate table urls restart identity;

CREATE TABLE urls (
    id integer NOT NULL PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name varchar(255) NOT NULL,
    created_at timestamp,
);