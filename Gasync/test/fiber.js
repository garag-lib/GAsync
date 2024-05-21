const fs = require('fs').promises;
const {createWriteStream, open} = require('fs');
const path = require('path');

// Constants
const FILE_SIZE = 1024 * 1024 * 10;
const BUFFER_SIZE = 1024;

function createFile() {
    console.log(`create file ${FILE_SIZE / 1024 / 1024} MB`);
    const file = createWriteStream('original.bin');
    for (let cont = 0, num = FILE_SIZE / BUFFER_SIZE; cont < num; cont++) {
        file.write(Buffer.alloc(BUFFER_SIZE, Math.random().toString(36).substring(2)));
    }
    file.end();
    console.log('file created');
}

function sleep(time) {
    return new Promise(resolve => setTimeout(resolve, time * 1000));
}

async function asyncTasks1() {
    console.log('1');
    await (async () => {
        console.log('2');
        await (async () => {
            console.log('3');
        })();
    })();
}

async function asyncTasks2() {
    console.log('4');
    console.log('***', await sleep(2));
    console.log('4.2');
}

async function asyncTasks3() {
    console.log('5');
    console.log('***', await sleep(3));
    console.log('5.3');
}

async function copyFile() {
    console.log('6');
    const origen = await fs.open('./original.bin', 'r');
    console.log('$origen ok');
    const destino = await fs.open('./copy.bin', 'w');
    console.log('$destino ok');
    let cont = 0;
    let bytesRead;
    let buffer = Buffer.alloc(BUFFER_SIZE * BUFFER_SIZE);

    while ((bytesRead = (await origen.read(buffer, 0, buffer.length, null)).bytesRead) > 0) {
        cont++;
        console.log(`${cont} leido: ${bytesRead}`);
        await destino.write(buffer.slice(0, bytesRead), 0, bytesRead);
        console.log(`${cont} escrito: ${bytesRead}`);
    }

    await origen.close();
    await destino.close();
}

function startTimer() {
    let cont = 0;
    const interval = setInterval(() => {
        cont++;
        console.log(`(${cont}) timer`);
        if (cont >= 10) {
            clearInterval(interval);
        }
    }, 1);
}

(async () => {
    createFile();
    startTimer();
    asyncTasks1();
    await copyFile();
    asyncTasks2();
    asyncTasks3();
    const memoryUsage = process.memoryUsage();
    console.log('\nmemory_get_peak_usage:', (memoryUsage.heapUsed / 1024 / 1024).toFixed(2), 'MB');
    console.log('memory_get_peak_usage total:', (memoryUsage.rss / 1024 / 1024).toFixed(2), 'MB');
})();