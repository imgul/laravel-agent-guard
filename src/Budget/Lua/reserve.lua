-- KEYS[1] = budget key, e.g. agent-guard:budget:{scope}:{tenant}:{agent}:{period}
-- ARGV[1] = cost to reserve (float, USD or tokens)
-- ARGV[2] = soft cap (float; <= 0 means "no soft cap")
-- ARGV[3] = hard cap (float; <= 0 means "no hard cap / unlimited")
-- ARGV[4] = TTL seconds
-- Returns: 1 = allowed (soft not exceeded), 101 = allowed (soft exceeded -> downshift),
--          0 = denied (would exceed hard cap)
local spent  = tonumber(redis.call('GET', KEYS[1]) or '0')
local cost   = tonumber(ARGV[1])
local soft   = tonumber(ARGV[2])
local hard   = tonumber(ARGV[3])
local ttl    = tonumber(ARGV[4])

if hard > 0 and spent + cost > hard then
  return 0
end

local soft_exceeded = 0
if soft > 0 and spent + cost > soft then
  soft_exceeded = 1
end

redis.call('INCRBYFLOAT', KEYS[1], cost)
redis.call('EXPIRE', KEYS[1], ttl)

if soft_exceeded == 1 then
  return 101
end
return 1
