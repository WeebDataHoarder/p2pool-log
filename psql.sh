#!/bin/bash

exec docker-compose exec --env PGPASSWORD=p2pool db psql --host db --username p2pool p2pool