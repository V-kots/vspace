import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

const baseUrl = __ENV.BASE_URL || 'https://vspace.loc';
const scenario = __ENV.SCENARIO || 'mixed';

const users = new SharedArray('credentials', () => {
  const raw = open('../credentials.jsonl');
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => JSON.parse(line));
});

const ramp = (name, exec, target) => ({
  [name]: {
    exec,
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '30s', target },
      { duration: '1m', target },
      { duration: '15s', target: 0 },
    ],
    gracefulRampDown: '10s',
  },
});

function buildScenarios() {
  if (scenario === 'login') {
    return ramp('login', 'login', 50);
  }
  if (scenario === 'get') {
    return ramp('get', 'getProfile', 80);
  }

  return {
    ...ramp('login', 'login', 50),
    ...ramp('get', 'getProfile', 80),
  };
}

export const options = {
  scenarios: buildScenarios(),
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

function pickUser() {
  return users[(__VU + __ITER) % users.length];
}

export function login() {
  const user = pickUser();
  const res = http.post(
    `${baseUrl}/login`,
    JSON.stringify({ id: user.id, password: user.password }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { name: 'POST /login' },
    },
  );

  check(res, {
    'login status 200': (r) => r.status === 200,
    'login has token': (r) => r.json('token') !== undefined,
  });

  sleep(0.1);
}

export function getProfile() {
  const user = pickUser();
  const res = http.get(`${baseUrl}/user/get/${user.id}`, {
    headers: { Accept: 'application/json' },
    tags: { name: 'GET /user/get/{id}' },
  });

  check(res, {
    'get status 200': (r) => r.status === 200,
    'get id matches': (r) => r.json('id') === user.id,
  });

  sleep(0.05);
}
