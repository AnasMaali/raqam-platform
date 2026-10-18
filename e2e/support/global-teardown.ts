import { dropTemplate } from './app';

export default function globalTeardown() {
    dropTemplate();
}
