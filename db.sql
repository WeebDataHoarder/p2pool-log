
CREATE TABLE miners (
    id bigserial PRIMARY KEY,
    address varchar UNIQUE NOT NULL
);

CREATE TABLE blocks (
    id varchar PRIMARY KEY, -- sidechain id
    height bigint UNIQUE NOT NULL, -- height on sidechain
    previous_id varchar UNIQUE NOT NULL, -- previous sidechain id
    main_height bigint NOT NULL,
    main_hash varchar UNIQUE NOT NULL, -- hash on mainchain
    main_found boolean NOT NULL,
    difficulty bigint NOT NULL,
    pow_hash varchar NOT NULL,
    timestamp bigint NOT NULL,
    miner bigint NOT NULL,
    tx_id varchar NOT NULL, -- coinbase transaction id
    tx_privkey varchar NOT NULL, -- used to match outputs from coinbase transaction
    FOREIGN KEY (miner) REFERENCES miners (id)
    -- FOREIGN KEY (previous_id) REFERENCES blocks (id)
);

CREATE TABLE uncles (
    id varchar PRIMARY KEY, -- sidechain id
    parent_id varchar NOT NULL, -- parent id on sidechain
    parent_height bigint NOT NULL, -- parent height on sidechain
    height bigint NOT NULL, -- height on sidechain
    previous_id varchar NOT NULL, -- previous sidechain id
    timestamp bigint NOT NULL,
    miner bigint NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES blocks (id),
    FOREIGN KEY (parent_height) REFERENCES blocks (height),
    FOREIGN KEY (miner) REFERENCES miners (id)
    -- FOREIGN KEY (previous_id) REFERENCES blocks (id)
);

CREATE TABLE subscriptions (
    miner bigint NOT NULL,
    nick varchar NOT NULL, -- IRC nickname
    PRIMARY KEY (miner, nick),
    FOREIGN KEY (miner) REFERENCES miners (id)
);

CREATE INDEX blocks_miner_idx ON blocks (miner);
CREATE INDEX uncles_parent_id_idx ON uncles (parent_id);
CREATE INDEX uncles_parent_height_idx ON uncles (parent_height);
CREATE INDEX uncles_miner_idx ON uncles (miner);

CREATE INDEX subscriptions_miner_idx ON subscriptions (miner);
CREATE INDEX nick_miner_idx ON subscriptions (nick);