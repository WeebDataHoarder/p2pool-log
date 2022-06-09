
CREATE TABLE miners (
    id bigserial PRIMARY KEY,
    address varchar UNIQUE NOT NULL
);

CREATE TABLE blocks (
    id varchar PRIMARY KEY, -- sidechain id
    height bigint UNIQUE NOT NULL, -- sidechain height
    previous_id varchar UNIQUE NOT NULL, -- previous sidechain id

    coinbase_id varchar NOT NULL, -- coinbase transaction id
    coinbase_reward bigint NOT NULL,
    coinbase_privkey varchar NOT NULL, -- used to match outputs from coinbase transaction

    difficulty varchar NOT NULL, -- current side chain difficulty
    timestamp bigint NOT NULL, -- timestamp as set in block
    miner bigint NOT NULL,
    pow_hash varchar NOT NULL, -- result of PoW function as a hash (all 0x00 = not known)

    main_height bigint NOT NULL,
    main_id varchar UNIQUE NOT NULL, -- Block id on mainchain
    main_found boolean NOT NULL DEFAULT 'n', -- for convenience, can be calculated from PoW hash and miner main difficulty, but can also be orphaned

    miner_main_id varchar NOT NULL, -- main chain id of previous block being mined against (all 0xFF = not known)
    miner_main_difficulty varchar NOT NULL, -- previous difficulty to match pow_hash against (all 0xFF = not known)

    FOREIGN KEY (miner) REFERENCES miners (id)
    -- FOREIGN KEY (previous_id) REFERENCES blocks (id)
);

CREATE TABLE uncles (
    parent_id varchar NOT NULL, -- parent id on sidechain
    parent_height bigint NOT NULL, -- parent height on sidechain

    -- same as blocks --

    id varchar PRIMARY KEY, -- sidechain id
    height bigint NOT NULL, -- sidechain height
    previous_id varchar NOT NULL, -- previous sidechain id

    coinbase_id varchar NOT NULL, -- coinbase transaction id
    coinbase_reward bigint NOT NULL,
    coinbase_privkey varchar NOT NULL, -- used to match outputs from coinbase transaction

    difficulty varchar NOT NULL, -- current side chain difficulty
    timestamp bigint NOT NULL, -- timestamp as set in block
    miner bigint NOT NULL,
    pow_hash varchar NOT NULL, -- result of PoW function as a hash (all 0x00 = not known)

    main_height bigint NOT NULL,
    main_id varchar NOT NULL, -- Block id on mainchain
    main_found boolean NOT NULL DEFAULT 'n', -- for convenience, can be calculated from PoW hash and miner main difficulty, but can also be orphaned

    miner_main_id varchar NOT NULL, -- main chain id of previous block being mined against (all 0xFF = not known)
    miner_main_difficulty varchar NOT NULL, -- previous difficulty to match pow_hash against (all 0xFF = not known)

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

CREATE TABLE coinbase_outputs (
  id varchar NOT NULL, -- coinbase tx id
  index int NOT NULL,
  miner bigint NOT NULL,
  amount bigint NOT NULL,
  PRIMARY KEY (id, index),
  FOREIGN KEY (miner) REFERENCES miners (id)
  -- FOREIGN KEY (id) REFERENCES blocks (coinbase_id)
);

CREATE INDEX blocks_coinbase_id_idx ON blocks (coinbase_id);
CREATE INDEX blocks_miner_idx ON blocks (miner);
CREATE INDEX blocks_main_height_idx ON blocks (main_height);
CREATE INDEX blocks_main_found_idx ON blocks (main_found);
CREATE INDEX uncles_coinbase_id_idx ON uncles (coinbase_id);
CREATE INDEX uncles_height_idx ON uncles (height);
CREATE INDEX uncles_parent_id_idx ON uncles (parent_id);
CREATE INDEX uncles_main_id_idx ON uncles (main_id);
CREATE INDEX uncles_parent_height_idx ON uncles (parent_height);
CREATE INDEX uncles_miner_idx ON uncles (miner);
CREATE INDEX uncles_main_height_idx ON uncles (main_height);
CREATE INDEX uncles_main_found_idx ON uncles (main_found);

CREATE INDEX subscriptions_miner_idx ON subscriptions (miner);
CREATE INDEX nick_miner_idx ON subscriptions (nick);

CREATE INDEX coinbase_outputs_id_idx ON coinbase_outputs (id);
CREATE INDEX coinbase_outputs_miner_idx ON coinbase_outputs (miner);